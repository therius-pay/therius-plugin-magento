<?php
namespace Therius\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Therius\Payment\Model\Api\Client;

/**
 * AJAX endpoint at /therius/checkout/purchase, called from
 * therius-method.js BEFORE Magento's own placeOrder() — this is what lets a
 * pending_ddc/pending_3ds actionRequired response come back to the SDK and
 * get resolved entirely client-side (Device Data Collection iframe / 3DS
 * challenge redirect) while we still only have a quote, not yet a real
 * Magento order. Mirrors the WooCommerce reference plugin's pre-order AJAX
 * purchase handler and its onActionComplete architecture — see
 * plugin-developer skill Section 10.
 *
 * On a final (non-challenge) outcome, the result is cached on the checkout
 * session and Model\Therius::doPurchase() picks it up instead of charging a
 * second time once Magento places the real order.
 */
class Purchase implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Non-error outcomes — see Client::buildPurchaseBody() callers / plugin-developer skill Section 3.4. */
    private const ACCEPTED_STATUSES = ['captured', 'authorized', 'pending'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly Client $client
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $requestData = json_decode((string) $this->request->getContent(), true) ?: [];

        $methodType = (string) ($requestData['methodType'] ?? 'card');
        $ddcSessionId = isset($requestData['ddcSessionId']) && $requestData['ddcSessionId'] !== ''
            ? (string) $requestData['ddcSessionId']
            : null;

        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return $result->setHttpResponseCode(400)->setData(['error' => __('No active cart.')]);
        }

        // Reserve (but don't consume) an order id now so the same orderCode
        // is used for every attempt on this quote — including a retried
        // purchase call after a DDC/3DS round-trip — and so it matches the
        // increment_id Magento assigns when the order is actually placed
        // right after this. Without this, each attempt got its own
        // "DEMO-<timestamp>" placeholder because no orderCode was ever set.
        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId()->save();
        }
        $orderCode = (string) $quote->getReservedOrderId();
        $storeId = (int) $quote->getStoreId();

        // Stashed unconditionally as soon as it's known — not deferred to the
        // final-success branch below — because Finalize.php (called once the
        // widget resolves an actionRequired action client-side) verifies
        // against this value, and the actionRequired branch below returns
        // before reaching the final-success branch. Without this, ANY
        // actionRequired outcome (ACH microdeposit, 3DS challenge, DDC round)
        // left therius_order_code unset on the checkout session, and
        // Finalize.php's `$expectedOrderCode === ''` check 422'd every time —
        // confirmed live 2026-08-11 via therius-finalize-debug logging.
        $this->checkoutSession->setData('therius_order_code', $orderCode);

        $methodPayload = match ($methodType) {
            'card' => ['nonce' => (string) ($requestData['nonce'] ?? '')],
            'apm' => is_array($requestData['apmData'] ?? null) ? $requestData['apmData'] : [],
            'wallet' => is_array($requestData['walletData'] ?? null) ? $requestData['walletData'] : [],
            default => [],
        };

        try {
            $body = $this->client->buildPurchaseBody(
                $methodType,
                $methodPayload,
                $quote->getBillingAddress(),
                (float) $quote->getGrandTotal(),
                (string) $quote->getQuoteCurrencyCode(),
                $orderCode,
                $storeId,
                $quote->getCustomerEmail(),
                $ddcSessionId
            );
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(422)->setData(['error' => $e->getMessage()]);
        }

        $idempotencyKey = 'm2_purchase_' . $orderCode . '_' . md5(json_encode($body));
        $apiResult = $this->client->purchase($body, $idempotencyKey, $storeId);
        $data = $apiResult['data'];

        if (!empty($data['actionRequired'])) {
            // Not final — hand the actionRequired payload back to the SDK
            // (via onActionComplete in therius-method.js) rather than
            // touching the checkout session; nothing to cache yet. Checked
            // purely on actionRequired's presence, NOT status — the API only
            // uses dedicated pending_ddc/pending_3ds statuses for the
            // internal 3DS pipeline; every other provider-native action
            // (ACH microdeposit verification, boleto/konbini vouchers, bank
            // redirects, Plaid Link) carries actionRequired alongside the
            // generic status "pending" instead. Gating on the two named
            // statuses alone (the previous CHALLENGE_STATUSES check) meant
            // an ACH purchase's actionRequired was silently dropped here and
            // self::ACCEPTED_STATUSES below let 'pending' fall through as if
            // the order were final — placeOrder() ran immediately, the
            // shopper was never shown the microdeposit verification page,
            // and the mandate was left permanently incomplete.
            return $result->setData(['actionRequired' => $data['actionRequired']]);
        }

        if ($apiResult['status'] >= 400 || empty($data['paymentCode']) || !in_array($data['status'] ?? '', self::ACCEPTED_STATUSES, true)) {
            return $result->setHttpResponseCode(422)->setData([
                'error' => Client::extractErrorMessage($data),
                'recoveryAction' => Client::extractRecoveryAction($data),
            ]);
        }

        // Picked up by Model\Therius::doPurchase() once Magento places the
        // real order right after this call returns — see that method's
        // docblock for why matching on the order_code (not just presence)
        // matters. therius_order_code is already set above (unconditionally,
        // before the actionRequired branch could return early).
        $this->checkoutSession->setData('therius_payment_code', $data['paymentCode']);
        if (!empty($data['id'])) {
            $this->checkoutSession->setData('therius_payment_id', $data['id']);
        }
        $this->checkoutSession->setData('therius_status', $data['status']);

        return $result->setData(['success' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
