<?php
namespace Therius\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Therius\Payment\Model\Api\Client;

/**
 * AJAX endpoint at /therius/checkout/finalize, called from
 * therius-method.js's onActionComplete handler once the Checkout Widget has
 * resolved a real 3DS *challenge* entirely client-side (sdk._resumePayment /
 * _3dsAdvance calls the Therius API directly from the browser, bypassing our
 * server) — this is the first this checkout sees of that outcome.
 * Re-derives it via GET /payment/inquiry rather than trusting the
 * widget-supplied paymentCode directly. Mirrors the WooCommerce reference
 * plugin's finalize_from_action_complete() field-for-field — see
 * plugin-developer skill Section 10.
 *
 * Verified against the orderCode reserved in Controller\Checkout\Purchase
 * (stashed on the checkout session as therius_order_code), NOT a
 * previously-stored paymentCode: the pending_ddc/pending_3ds purchase
 * responses carry no top-level paymentCode at all — only
 * actionRequired.paymentCode, which is a 3DS/DDC *session* id, not the
 * payment's own code — so there's never a paymentCode already stored for
 * exactly the orders that reach this controller.
 */
class Finalize implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACCEPTED_STATUSES = ['captured', 'authorized', 'pending', 'approved', 'succeeded'];

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

        $submittedCode = (string) ($requestData['paymentCode'] ?? '');
        $expectedOrderCode = (string) $this->checkoutSession->getData('therius_order_code');

        if ($submittedCode === '' || $expectedOrderCode === '') {
            return $result->setHttpResponseCode(422)->setData(['error' => __('Payment error: could not verify payment outcome.')]);
        }

        $storeId = (int) $this->checkoutSession->getQuote()->getStoreId();

        // The widget's onActionComplete fires the instant its own /resume
        // (or /3ds_advance) call returns the final status — but that
        // response and this inquiry call are two independent requests, and
        // the WooCommerce reference plugin observed GET /payment/inquiry
        // briefly 404 ("Payment does not exist") for a paymentCode that
        // /resume had just reported as captured seconds earlier. A short
        // retry absorbs that read-after-write gap instead of failing an
        // otherwise-successful payment.
        $maxAttempts = 3;
        $data = [];
        $status = 0;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $apiResult = $this->client->inquiry($submittedCode, $storeId);
            $status = $apiResult['status'];
            $data = $apiResult['data'];

            if ($status < 400 && !empty($data['status'])) {
                break;
            }
            if ($attempt < $maxAttempts) {
                usleep(700000); // 0.7s
            }
        }

        if ($status >= 400 || empty($data['status'])) {
            $message = $data['error']['message'] ?? __('Payment error: could not verify payment outcome.');
            return $result->setHttpResponseCode(422)->setData(['error' => (string) $message]);
        }

        if (empty($data['orderCode']) || $data['orderCode'] !== $expectedOrderCode) {
            return $result->setHttpResponseCode(422)->setData(['error' => __('Payment error: could not verify payment outcome.')]);
        }

        if (!in_array($data['status'], self::ACCEPTED_STATUSES, true)) {
            $message = $data['error']['message'] ?? __('Payment declined.');
            return $result->setHttpResponseCode(422)->setData(['error' => (string) $message]);
        }

        // Picked up by Model\Therius::doPurchase() once Magento places the
        // real order right after this call returns, same as the
        // synchronous-purchase path in Controller\Checkout\Purchase.
        $this->checkoutSession->setData('therius_payment_code', $data['paymentCode']);
        $this->checkoutSession->setData('therius_status', $data['status']);
        // therius_order_code is already set from Purchase; leave it as-is.

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
