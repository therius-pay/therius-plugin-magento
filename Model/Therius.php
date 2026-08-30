<?php
namespace Therius\Payment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Therius\Payment\Model\Api\Client;

/**
 * Therius payment method. Deliberately mirrors the shape of the WooCommerce
 * reference plugin's process_payment()/process_refund() rather than the
 * Magento Payment Gateway command-pool pattern: Therius does its own
 * authorize/capture/pending decisioning server-side and confirms the final
 * state asynchronously via webhook (see Controller\Webhook\Index), so there
 * is no per-step gateway command sequence to model — a single purchase call
 * plus a single refund call is the whole contract, same as WooCommerce.
 *
 * The actual purchase call lives in doPurchase(), called from BOTH
 * authorize() and capture() — see doPurchase()'s docblock. Confirmed live
 * (2026-08-10) that Magento's default "authorize_capture" payment_action
 * calls only capture() in this Magento version (no SaleOperationInterface
 * on this class), not authorize()+capture() as the docs/tutorials for older
 * Magento versions describe — putting the real logic in only one of them
 * left orders "succeeding" in Magento with no Therius charge ever made.
 */
class Therius extends AbstractMethod
{
    public const CODE = 'therius';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;

    /** Set by the JS payment component via additional_data before order placement. */
    private const ADDITIONAL_DATA_KEYS = [
        'therius_method',
        'therius_nonce',
        'therius_apm_data',
        'therius_wallet_data',
    ];

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        private readonly Client $client,
        private readonly CheckoutSession $checkoutSession,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Persist the additional_data the JS payment component attaches
     * (therius_method / therius_nonce / therius_apm_data / therius_wallet_data)
     * onto the order payment so authorize() can read it back.
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData('additional_data') ?: [];
        $info = $this->getInfoInstance();
        foreach (self::ADDITIONAL_DATA_KEYS as $key) {
            if (isset($additionalData[$key])) {
                $info->setAdditionalInformation($key, $additionalData[$key]);
            }
        }

        return $this;
    }

    /**
     * The actual /v1/payment/purchase call. Shared between authorize() and
     * capture() (below) — NOT called from just one of them, because which
     * one Magento actually invokes depends on the payment_action config and
     * Magento's own internal dispatch, not on anything this class controls:
     * for the default "authorize_capture" action, Magento 2.4.x's
     * Order\Payment::place() only calls authorize()+capture() back-to-back
     * when the method implements SaleOperationInterface (canSale()) — ours
     * doesn't, so it calls ONLY capture() via Order\Payment::capture() ->
     * orderPaymentProcessor. If payment_action were instead set to plain
     * "authorize", Magento calls ONLY authorize(). Putting the real logic in
     * just one of them left the other a silent no-op depending on which path
     * ran — confirmed live: capture() was the one actually invoked, so every
     * order "succeeded" in Magento without ever calling Therius. Safe to call
     * from both without double-charging: the idempotency key below is
     * derived from the order+body content, so a duplicate call within the
     * API's idempotency window returns the cached first result.
     *
     * If a card/APM/wallet purchase needed a 3DS challenge or Device Data
     * Collection step, Controller\Checkout\Purchase already resolved that
     * entirely client-side (see therius-method.js's onActionComplete) before
     * Magento ever placed this order, and stashed the outcome in the
     * checkout session (therius_payment_code/therius_order_code/
     * therius_status). If present and it matches THIS order, use it directly
     * instead of re-charging — the API call has already happened. This is
     * only ever session data from the checkout that just placed this order
     * (read-once, cleared immediately below), never a stale/foreign value.
     */
    private function doPurchase(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();

        $sessionOrderCode = $this->checkoutSession->getData('therius_order_code');
        if ($sessionOrderCode && $sessionOrderCode === $order->getIncrementId()) {
            $paymentCode = (string) $this->checkoutSession->getData('therius_payment_code');
            $paymentId = (string) $this->checkoutSession->getData('therius_payment_id');
            $status = (string) $this->checkoutSession->getData('therius_status');
            $this->checkoutSession->unsetData('therius_payment_code');
            $this->checkoutSession->unsetData('therius_payment_id');
            $this->checkoutSession->unsetData('therius_order_code');
            $this->checkoutSession->unsetData('therius_status');

            $this->applyPurchaseResult($payment, $order, $paymentCode, $paymentId, $status);
            return $this;
        }

        // Fallback: no pre-order session data (e.g. a payment_action config
        // that skips the pre-order AJAX call, or a retry after session
        // expiry) — charge directly here, same as before the DDC/3DS work.
        $currency = $order->getOrderCurrencyCode();
        $methodType = $payment->getAdditionalInformation('therius_method') ?: 'card';

        $methodPayload = match ($methodType) {
            'card' => ['nonce' => (string) $payment->getAdditionalInformation('therius_nonce')],
            'apm' => json_decode((string) $payment->getAdditionalInformation('therius_apm_data'), true) ?: [],
            'wallet' => json_decode((string) $payment->getAdditionalInformation('therius_wallet_data'), true) ?: [],
            default => [],
        };

        $body = $this->client->buildPurchaseBody(
            $methodType,
            $methodPayload,
            $order->getBillingAddress(),
            (float) $amount,
            $currency,
            $order->getIncrementId(),
            $storeId,
            $order->getCustomerEmail()
        );

        $idempotencyKey = 'm2_purchase_' . $order->getIncrementId() . '_' . md5(json_encode($body));

        $result = $this->client->purchase($body, $idempotencyKey, $storeId);
        $data = $result['data'];

        // 'pending' is a real, expected outcome (e.g. async fraud/issuer
        // review) — its final result arrives later via webhook, so it must
        // not be treated as a decline. Matches the WooCommerce reference.
        $acceptedStatuses = ['captured', 'authorized', 'pending'];
        if ($result['status'] >= 400 || empty($data['paymentCode']) || !in_array($data['status'] ?? '', $acceptedStatuses, true)) {
            $message = $data['error']['message'] ?? __('Payment declined.');
            throw new LocalizedException(__($message));
        }

        $this->applyPurchaseResult($payment, $order, (string) $data['paymentCode'], (string) ($data['id'] ?? ''), (string) $data['status']);

        return $this;
    }

    /**
     * Applies an already-known purchase outcome (paymentCode + id + status) to
     * the order/payment. Shared by both the session-prefetched path and the
     * direct-call fallback in doPurchase() above.
     */
    private function applyPurchaseResult(InfoInterface $payment, $order, string $paymentCode, string $paymentId, string $status): void
    {
        if ($paymentCode === '') {
            throw new LocalizedException(__('Payment declined.'));
        }

        // Stored so the webhook handler can match this order to later
        // asynchronous events (capture confirmation, refund, chargeback).
        // Field name is paymentCode, NOT id — the /v1/payment/purchase
        // response has no "id" field at all (see the API's paymentResponse
        // struct in therius-public-api/handlers_payment.go); matches the
        // WooCommerce reference plugin's own explicit comment on this.
        $payment->setTransactionId($paymentCode);
        $payment->setAdditionalInformation('therius_payment_code', $paymentCode);
        // The Therius payment id (UUID) is the handle for refund / capture /
        // cancel — POST /v1/payment/{id}/refund. Stored here; an order placed
        // before this field existed falls back to resolvePaymentId() below.
        if ($paymentId !== '') {
            $payment->setAdditionalInformation('therius_payment_id', $paymentId);
        }
        $payment->setAdditionalInformation('therius_status', $status);
        $payment->setIsTransactionClosed(false);

        if ($status === 'pending') {
            $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                ->setStatus('pending_payment');
            $order->addCommentToStatusHistory(
                __('Therius: payment pending review, awaiting outcome via webhook.')
            );
        }
    }

    /**
     * Whichever of authorize()/capture() Magento actually calls for the
     * configured payment_action (see doPurchase()'s docblock), this is where
     * the real /v1/payment/purchase call happens.
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        // A payment_action of plain "authorize" calls only authorize(); if
        // capture() already ran first for some other action combination,
        // don't call Therius a second time for the same payment object.
        if ($payment->getAdditionalInformation('therius_payment_code')) {
            return $this;
        }
        return $this->doPurchase($payment, $amount);
    }

    /**
     * See doPurchase()'s docblock — this is where the real purchase call
     * actually happens for the default "authorize_capture" payment_action in
     * this Magento version, not authorize() above.
     */
    public function capture(InfoInterface $payment, $amount)
    {
        if ($payment->getAdditionalInformation('therius_payment_code')) {
            return $this;
        }
        return $this->doPurchase($payment, $amount);
    }

    /**
     * Resolve the Therius payment id (UUID) for a payment — the handle for the
     * lifecycle endpoints (POST /v1/payment/{id}/refund|capture|cancel).
     *
     * Returns the id stored at purchase time; for a payment placed before that
     * additional-info key existed, resolves it once from the stored
     * paymentCode via the keyless GET /v1/payment/inquiry/{code} (which also
     * returns the id) and caches it. Returns '' when it cannot be determined.
     */
    private function resolvePaymentId(InfoInterface $payment, int $storeId): string
    {
        $id = (string) $payment->getAdditionalInformation('therius_payment_id');
        if ($id !== '') {
            return $id;
        }

        $paymentCode = (string) $payment->getAdditionalInformation('therius_payment_code');
        if ($paymentCode === '') {
            return '';
        }

        try {
            $result = $this->client->inquiry($paymentCode, $storeId);
        } catch (\Throwable $e) {
            return '';
        }
        if (($result['status'] ?? 500) >= 400 || empty($result['data']['id'])) {
            return '';
        }

        $id = (string) $result['data']['id'];
        $payment->setAdditionalInformation('therius_payment_id', $id);
        return $id;
    }

    /**
     * POST /v1/payment/{id}/refund. A successful call only means Therius accepted
     * the request — the order should not be treated as fully refunded until
     * the payment.refunded webhook confirms it (see process_webhook_event()
     * in Controller\Webhook\Index). Magento's own credit-memo flow already
     * reflects the refund locally; this call keeps Therius in sync.
     */
    public function refund(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $currency = $order->getOrderCurrencyCode();
        $reason = $payment->getCreditMemo() ? (string) $payment->getCreditMemo()->getIncrementId() : '';

        $paymentId = $this->resolvePaymentId($payment, $storeId);
        if ($paymentId === '') {
            throw new LocalizedException(__('Could not determine the Therius payment id for this order.'));
        }

        $body = [
            'key' => $this->client->getPrivateKey($storeId),
            'amount' => [
                'value' => $this->client->toMinorUnits((float) $amount, $currency),
                'currency' => $currency,
                'exponent' => $this->client->currencyExponent($currency),
            ],
            'reference' => $reason,
        ];

        $merchantCode = $this->client->getMerchantCode($storeId);
        if ($merchantCode !== '') {
            $body['merchantCode'] = $merchantCode;
        }

        // Dedupe repeated (order, amount, reason) refund attempts within a
        // short window, same as the WooCommerce reference's transient.
        $dedupeKey = md5($order->getIncrementId() . '|' . $amount . '|' . $reason);
        $idempotencyCache = $payment->getAdditionalInformation('therius_refund_idem_' . $dedupeKey);
        $idempotencyKey = $idempotencyCache ?: bin2hex(random_bytes(16));
        $payment->setAdditionalInformation('therius_refund_idem_' . $dedupeKey, $idempotencyKey);

        $result = $this->client->refund($paymentId, $body, $idempotencyKey, $storeId);
        $data = $result['data'];

        if ($result['status'] >= 400) {
            $message = $data['error']['message'] ?? __('Refund failed.');
            throw new LocalizedException(__($message));
        }

        $order->addCommentToStatusHistory(
            __('Therius refund of %1 initiated. Order will move to Refunded once confirmed by webhook.', $amount)
        );

        return $this;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        // Basic sanity check: don't offer the method if no key is configured
        // for the active environment (test vs. live).
        $storeId = $quote ? (int) $quote->getStoreId() : null;
        return $this->client->getPrivateKey($storeId) !== '';
    }
}
