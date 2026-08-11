<?php
namespace Therius\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Therius\Payment\Model\Api\Client;

/**
 * Webhook receiver bound to https://<site>/therius/webhook/index
 * (etc/frontend/routes.xml: frontName "therius" -> module "Therius_Payment",
 * controller folder "Webhook", action "Index").
 *
 * Mirrors WC_Gateway_Therius::handle_webhook() / process_webhook_event() in
 * the WooCommerce reference plugin field-for-field — see plugin-developer
 * skill Section 3.7.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Events that only note the order rather than changing its state. */
    private const NOOP_EVENTS = ['payment.authorized'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $resultRawFactory,
        private readonly Client $client,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $rawBody = $this->request->getContent();
        $signatureHeader = (string) $this->request->getHeader('X-Therius-Signature');

        $data = json_decode((string) $rawBody, true);
        if (!is_array($data) || empty($data['event'])) {
            return $this->respond(400, 'invalid payload');
        }

        // Deliveries carry their originating environment so the same
        // endpoint can be used for both a Sandbox and a Production webhook
        // config, same as the WooCommerce reference.
        $sandbox = ($data['environment'] ?? '') === 'sandbox';
        $secret = $this->client->getWebhookSecret($sandbox);

        if ($secret === '' || !$this->verifySignature($rawBody, $signatureHeader, $secret)) {
            return $this->respond(401, 'invalid signature');
        }

        $this->processEvent($data);

        return $this->respond(200, 'ok');
    }

    /**
     * X-Therius-Signature: sha256=HMAC-SHA256(raw_body, signing_secret).
     */
    private function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === '' || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    private function processEvent(array $data): void
    {
        $event = $data['event'];
        $payload = is_array($data['data'] ?? null) ? $data['data'] : [];

        $order = $this->findOrderForWebhook($payload);
        if (!$order) {
            // Nothing to reconcile against; still ack 200 so Therius doesn't
            // keep retrying.
            return;
        }

        // Deliveries retry on failure and can arrive more than once for the
        // same event — skip re-applying one we've already seen.
        $eventKey = $event . ':' . ($data['created_at'] ?? '');
        $payment = $order->getPayment();
        if ($payment->getAdditionalInformation('therius_last_event') === $eventKey) {
            return;
        }

        if (in_array($event, self::NOOP_EVENTS, true)) {
            return;
        }

        switch ($event) {
            case 'payment.captured':
                if (!in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
                    $order->setState(Order::STATE_PROCESSING)->setStatus('processing');
                    $order->addCommentToStatusHistory(__('Therius: payment captured (confirmed via webhook).'));
                }
                break;

            case 'payment.refused':
                $order->setState(Order::STATE_CANCELED)->setStatus('canceled');
                $order->addCommentToStatusHistory(__('Therius: payment refused (confirmed via webhook).'));
                break;

            case 'payment.refunded':
                if ($order->getState() !== Order::STATE_CLOSED) {
                    $order->setState(Order::STATE_CLOSED)->setStatus('closed');
                    $order->addCommentToStatusHistory(__('Therius: payment refunded (confirmed via webhook).'));
                }
                break;

            case 'payment.cancelled':
                $order->setState(Order::STATE_CANCELED)->setStatus('canceled');
                $order->addCommentToStatusHistory(__('Therius: payment cancelled (confirmed via webhook).'));
                break;

            case 'payment.chargeback':
                $order->setState(Order::STATE_PAYMENT_REVIEW)->setStatus('payment_review');
                $order->addCommentToStatusHistory(
                    __('Therius: chargeback/dispute opened on this payment — needs manual review.')
                );
                break;

            case 'payment.capture_failed':
                $order->setState(Order::STATE_PAYMENT_REVIEW)->setStatus('payment_review');
                $order->addCommentToStatusHistory(__('Therius: capture attempt failed — needs manual review.'));
                break;

            case 'payment.refund_failed':
                // Don't move the order off its current status (the customer's
                // payment is unaffected — only the refund attempt failed).
                $order->addCommentToStatusHistory(__('Therius: refund attempt failed — needs manual review and retry.'));
                break;

            case 'payment.cancel_failed':
                $order->setState(Order::STATE_PAYMENT_REVIEW)->setStatus('payment_review');
                $order->addCommentToStatusHistory(__('Therius: cancellation attempt failed — needs manual review.'));
                break;

            default:
                $order->addCommentToStatusHistory(__('Therius: received unhandled webhook event "%1".', $event));
                $order->save();
                return; // Don't record as last-applied for events we didn't act on.
        }

        $payment->setAdditionalInformation('therius_last_event', $eventKey);
        $order->save();
    }

    /**
     * Look up the order a webhook event refers to. Prefers the stored
     * Therius payment_code (set in Therius::applyPurchaseResult()) — matches
     * on payload.payment_code, NOT payload.payment_id (that's an internal
     * UUID we never store; see services_webhook.go, and the WooCommerce
     * reference plugin's explicit comment on this same distinction). Falls
     * back to order_code (the increment id).
     */
    private function findOrderForWebhook(array $payload): ?Order
    {
        if (!empty($payload['payment_code'])) {
            $orderIds = $this->orderIdsByPaymentAdditionalInfo('therius_payment_code', $payload['payment_code']);
            if ($orderIds) {
                $collection = $this->orderCollectionFactory->create();
                $collection->addFieldToFilter('entity_id', ['in' => $orderIds])->setPageSize(1);
                $order = $collection->getFirstItem();
                if ($order && $order->getId()) {
                    return $order;
                }
            }
        }

        if (!empty($payload['order_code'])) {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $payload['order_code'])->setPageSize(1);
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        return null;
    }

    /**
     * additional_information is stored as a serialized blob on
     * sales_order_payment, so it can't be filtered directly in SQL — this
     * scans payments the same way the WooCommerce reference queries WP
     * postmeta via wc_get_orders(meta_key/meta_value).
     *
     * NOTE: not verified against a live database — see plugin README for
     * the unverified-item list. On stores with a very large order volume
     * this table scan may need to be replaced with an indexed lookup table.
     */
    private function orderIdsByPaymentAdditionalInfo(string $key, string $value): array
    {
        $collection = $this->orderCollectionFactory->create();
        $connection = $collection->getConnection();
        $select = $connection->select()
            ->from($collection->getTable('sales_order_payment'), ['parent_id', 'additional_information']);
        $rows = $connection->fetchAll($select);

        $matches = [];
        foreach ($rows as $row) {
            $info = json_decode((string) $row['additional_information'], true) ?: [];
            if (($info[$key] ?? null) === $value) {
                $matches[] = $row['parent_id'];
            }
        }
        return $matches;
    }

    private function respond(int $status, string $message)
    {
        $result = $this->resultRawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setContents($message);
        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Webhook is authenticated by HMAC signature, not a Magento form key.
        return true;
    }
}
