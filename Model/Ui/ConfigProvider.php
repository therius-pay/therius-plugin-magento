<?php
namespace Therius\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Therius\Payment\Model\Api\Client;
use Therius\Payment\Model\Therius;

/**
 * Feeds the frontend payment component (view/frontend/web/js/view/payment/method-renderer/therius-method.js)
 * everything it needs to open a Therius SDK session and mount the checkout
 * widget, mirroring the params the WooCommerce reference plugin passes via
 * wp_localize_script('woocommerce_therius', 'therius_params', ...).
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getConfig(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $storeId = (int) $this->storeManager->getStore()->getId();
        $currency = $quote ? $quote->getQuoteCurrencyCode() : $this->storeManager->getStore()->getCurrentCurrencyCode();
        $country = $quote && $quote->getBillingAddress()
            ? (string) $quote->getBillingAddress()->getCountryId()
            : '';

        // The real Therius API rejects createSdkSession without a country,
        // but the quote's billing address is empty on a guest's very first
        // page load (before they've entered anything) — window.checkoutConfig
        // is only populated once, at that initial load, so without a
        // fallback the session call fails silently (empty clientToken, no
        // exception) every single time for a fresh checkout. The store's
        // configured default country is a safe placeholder for bootstrapping
        // the SDK session; it isn't the shopper's actual billing country.
        $sessionCountry = $country !== '' ? $country : (string) $this->scopeConfig->getValue('general/country/default');

        $sessionBody = [
            'country' => $sessionCountry,
            'currency' => $currency,
        ];
        if ($this->customerSession->isLoggedIn()) {
            $sessionBody['customerId'] = (string) $this->customerSession->getCustomerId();
        }
        $checkoutConfigId = $this->client->getCheckoutConfigId($storeId);
        if ($checkoutConfigId !== '') {
            $sessionBody['checkoutConfigId'] = $checkoutConfigId;
        }

        $clientToken = '';
        $privateKey = $this->client->getPrivateKey($storeId);
        if ($privateKey !== '') {
            $result = $this->client->createSdkSession($sessionBody, $storeId);
            $clientToken = $result['data']['clientToken'] ?? '';
        }

        return [
            'payment' => [
                Therius::CODE => [
                    'clientToken' => $clientToken,
                    'baseUrl' => $this->client->getApiUrl($storeId),
                    'currency' => $currency,
                    // The SDK's checkoutOptions.amount (and the real API's
                    // amount.value) are minor units (e.g. cents), not the
                    // shopper-facing major-unit total — confirmed against
                    // the SDK bundle's own _formatAmount() (divides by 100
                    // for display) and a real 422 from POST
                    // /v1/payment/authorization rejecting a raw float.
                    'amount' => $quote ? $this->client->toMinorUnits((float) $quote->getGrandTotal(), $currency) : 0,
                    'country' => $country,
                    'checkoutConfigId' => $checkoutConfigId,
                    'merchantCode' => $this->client->getMerchantCode($storeId),
                ],
            ],
        ];
    }
}
