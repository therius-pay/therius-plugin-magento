<?php
namespace Therius\Payment\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;

/**
 * Thin wrapper around the Therius API. Mirrors the WooCommerce reference
 * plugin's purchase/refund/session calls field-for-field so behaviour stays
 * identical across platforms (see plugin-developer skill, Section 3).
 */
class Client
{
    private const PATH_PREFIX = 'payment/therius/';

    /**
     * Currencies with no minor unit — the major amount IS the minor-unit
     * value (no x100), exponent 0. Matches therius-plugin-woocommerce and
     * therius-sdk-example/src/lib/amount.ts.
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Curl $curl,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTestMode(?int $storeId = null): bool
    {
        return (bool) $this->getConfig('test_mode', $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return $this->isTestMode($storeId) ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
    }

    public function getPrivateKey(?int $storeId = null): string
    {
        // test_private_key/private_key use backend_model=Encrypted (system.xml),
        // so the raw scopeConfig value is ciphertext (Magento's "0:3:..."
        // format) — must be explicitly decrypted, ScopeConfig does not do
        // this automatically outside the admin config form itself.
        $raw = (string) $this->getConfig($this->isTestMode($storeId) ? 'test_private_key' : 'private_key', $storeId);
        return $raw === '' ? '' : $this->encryptor->decrypt($raw);
    }

    public function getPublishableKey(?int $storeId = null): string
    {
        return (string) $this->getConfig($this->isTestMode($storeId) ? 'test_publishable_key' : 'publishable_key', $storeId);
    }

    public function getCheckoutConfigId(?int $storeId = null): string
    {
        return (string) $this->getConfig(
            $this->isTestMode($storeId) ? 'test_checkout_config_id' : 'live_checkout_config_id',
            $storeId
        );
    }

    public function getMerchantCode(?int $storeId = null): string
    {
        return (string) $this->getConfig('merchant_code', $storeId);
    }

    public function getWebhookSecret(bool $sandbox, ?int $storeId = null): string
    {
        // Same encrypted-field issue as getPrivateKey() above.
        $raw = (string) $this->getConfig($sandbox ? 'test_webhook_secret' : 'webhook_secret', $storeId);
        return $raw === '' ? '' : $this->encryptor->decrypt($raw);
    }

    /**
     * Convert a major-unit amount (e.g. Magento order total) to the
     * minor-unit integer value the Therius API expects.
     */
    public function toMinorUnits(float $major, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($major);
        }
        return (int) round($major * 100);
    }

    public function currencyExponent(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    /**
     * POST /v1/payment/purchase
     */
    public function purchase(array $body, string $idempotencyKey, ?int $storeId = null): array
    {
        return $this->post('/v1/payment/purchase', $body, $idempotencyKey, $storeId);
    }

    /**
     * Builds the /v1/payment/purchase request body — shared between
     * Controller\Checkout\Purchase (pre-order, has a quote, no Magento order
     * yet) and Model\Therius::doPurchase()'s direct-call fallback (has a
     * real order). $billingAddress is either a Quote\Address or Order\Address
     * — both expose the same field getters used here (Magento convention),
     * so this stays object-shape-agnostic on purpose; pass null if no
     * billing address is available yet.
     *
     * $ddcSessionId, when set, is sent as threeDsSetup.sessionId — the
     * pending_ddc pause needs this to know which completed device-data-
     * collection round to resume (see the CheckoutOptions.onNonce docblock
     * in therius-sdk/src/types.ts).
     */
    public function buildPurchaseBody(
        string $methodType,
        array $methodPayload,
        $billingAddress,
        float $amount,
        string $currency,
        string $orderCode,
        ?int $storeId,
        ?string $customerEmail = null,
        ?string $ddcSessionId = null
    ): array {
        $body = [
            'amount' => [
                'value' => $this->toMinorUnits($amount, $currency),
                'currency' => $currency,
                'exponent' => $this->currencyExponent($currency),
            ],
            'key' => $this->getPrivateKey($storeId),
        ];

        $merchantCode = $this->getMerchantCode($storeId);
        if ($merchantCode !== '') {
            $body['merchantCode'] = $merchantCode;
        }

        $fullName = $billingAddress
            ? trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname())
            : '';
        // Several downstream gateways (confirmed live: CyberSource's
        // orderInformation.billTo.administrativeArea) hard-require a billing
        // address — specifically the state/region — and decline with
        // MISSING_FIELD without it.
        $cardAddress = $billingAddress ? [
            'address1' => $billingAddress->getStreetLine1() ?: null,
            'address2' => $billingAddress->getStreetLine2() ?: null,
            'city' => $billingAddress->getCity() ?: null,
            // getRegionCode() returns the short code (e.g. "CA") CyberSource's
            // administrativeArea expects; falls back to the free-text region
            // for countries without a coded region list.
            'state' => $billingAddress->getRegionCode() ?: ($billingAddress->getRegion() ?: null),
            'countryCode' => $billingAddress->getCountryId() ?: null,
            'postalCode' => $billingAddress->getPostcode() ?: null,
        ] : null;

        switch ($methodType) {
            case 'card':
                $nonce = (string) ($methodPayload['nonce'] ?? '');
                if ($nonce === '') {
                    throw new LocalizedException(__('Payment error: Missing Therius payment nonce.'));
                }
                $body['card'] = [
                    'nonceData' => [
                        'nonce' => $nonce,
                        'cardholderName' => $fullName,
                        'cardAddress' => $cardAddress,
                    ],
                ];
                break;

            case 'apm':
                $apmData = $methodPayload;
                if (empty($apmData)) {
                    throw new LocalizedException(__('Payment error: Missing APM data.'));
                }
                $apmData['payerName'] = $apmData['payerName'] ?? $fullName;
                $apmData['payerEmail'] = $apmData['payerEmail'] ?? $customerEmail;
                if ($cardAddress) {
                    $apmData['billingAddress'] = $cardAddress;
                }
                // Mandate-based APM methods (confirmed live: Stripe ACH) need
                // a real browser user agent for their online-consent mandate
                // — but the API only reads it from the top-level
                // browserInfo.userAgent field (handlers_payment.go's
                // gwReq.UserAgent = req.BrowserInfo.UserAgent), never from
                // inside apm itself. The SDK hands us userAgent nested in the
                // apm payload (matching the ACH panel's own field, which we
                // still forward as-is below for the gateway adapter that
                // reads it there) — lift a copy up to browserInfo.userAgent
                // too so the mandate isn't silently sent empty. IP address
                // needs no equivalent handling: the API falls back to the
                // connecting client's own IP server-side when unset.
                if (!empty($apmData['userAgent'])) {
                    $body['browserInfo'] = ['userAgent' => (string) $apmData['userAgent']];
                }
                $body['apm'] = $apmData;
                break;

            case 'wallet':
                $walletData = $methodPayload;
                if (empty($walletData)) {
                    throw new LocalizedException(__('Payment error: Missing Wallet data.'));
                }
                $body['wallet'] = $walletData;
                break;

            default:
                throw new LocalizedException(__('Payment error: Unknown payment method.'));
        }

        if ($ddcSessionId !== null && $ddcSessionId !== '') {
            $body['threeDsSetup'] = ['sessionId' => $ddcSessionId];
        }

        // Hashed BEFORE orderCode/paymentCode are added below, so the hash
        // reflects only the actual charge payload (amount/card/apm/wallet/
        // threeDsSetup) — otherwise paymentCode would need to be part of its
        // own hash input, which is circular.
        //
        // orderCode stays the stable per-quote reserved order id across
        // every attempt (needed so GET /payment/inquiry — see Client::inquiry()
        // and Controller\Checkout\Finalize — can be verified against a single,
        // predictable value). Without an explicit, distinct paymentCode per
        // attempt, therius-public-api defaults paymentCode to orderCode, and
        // its Lookup uniqueness check is on the (order_code, payment_code)
        // PAIR — so a second attempt on the same quote (e.g. an ACH retry, or
        // a DDC/3DS continuation with a different payload) collides with the
        // first with "Payment already exists within the order". Confirmed
        // live (2026-08-10): an ACH retry on an already-reserved order id hit
        // exactly this. Same short-hash approach as the WooCommerce
        // reference plugin: an exact double-click (identical payload)
        // reproduces the same hash -> same paymentCode -> correctly caught
        // as "already exists"; any real retry (new nonce, different
        // bank/card data, DDC/3DS continuation, etc.) has a different
        // payload -> different paymentCode -> no collision.
        $payloadHash = md5(json_encode($body));
        $body['orderCode'] = $orderCode;
        $body['paymentCode'] = $orderCode . '_' . substr($payloadHash, 0, 8);

        return $body;
    }

    /**
     * POST /v1/payment/refund
     */
    public function refund(array $body, string $idempotencyKey, ?int $storeId = null): array
    {
        return $this->post('/v1/payment/refund', $body, $idempotencyKey, $storeId);
    }

    /**
     * POST /v1/sdk/session — returns a clientToken for the frontend SDK.
     */
    public function createSdkSession(array $body, ?int $storeId = null): array
    {
        return $this->post('/v1/sdk/session', $body, null, $storeId);
    }

    /**
     * GET /v1/payment/inquiry/:paymentCode — used to re-derive the
     * authoritative outcome of a payment the Checkout Widget finished
     * resolving entirely client-side (a real 3DS challenge — see
     * onActionComplete in therius-method.js), which our server never
     * otherwise sees the result of. Mirrors the WooCommerce reference's
     * finalize_from_action_complete().
     */
    public function inquiry(string $paymentCode, ?int $storeId = null): array
    {
        return $this->get('/v1/payment/inquiry/' . rawurlencode($paymentCode), $storeId);
    }

    private function get(string $path, ?int $storeId): array
    {
        $url = $this->getApiUrl($storeId) . $path;

        $headers = [
            'Authorization: Bearer ' . $this->getPrivateKey($storeId),
        ];
        // GET /payment/inquiry takes no request body to derive environment
        // from (unlike purchase/refund's sk_sandbox_/sk_live_ key prefix
        // convention) — without this header it defaults to production and a
        // sandbox payment simply isn't found. Same as the WooCommerce
        // reference's finalize_from_action_complete().
        if ($this->isTestMode($storeId)) {
            $headers[] = 'X-Environment: sandbox';
        }

        $this->curl->setHeaders($this->headersToAssoc($headers));
        $this->curl->setOption(CURLOPT_TIMEOUT, 45);
        $this->curl->get($url);

        $status = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();
        $data = json_decode((string) $responseBody, true) ?: [];

        return [
            'status' => $status,
            'data' => $data,
        ];
    }

    private function post(string $path, array $body, ?string $idempotencyKey, ?int $storeId): array
    {
        $url = $this->getApiUrl($storeId) . $path;

        $headers = [
            'Authorization: Bearer ' . $this->getPrivateKey($storeId),
            'Content-Type: application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $this->curl->setHeaders($this->headersToAssoc($headers));
        $this->curl->setOption(CURLOPT_TIMEOUT, 45);
        $this->curl->post($url, json_encode($body));

        $status = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();
        $data = json_decode((string) $responseBody, true) ?: [];

        return [
            'status' => $status,
            'data' => $data,
        ];
    }

    private function headersToAssoc(array $rawHeaders): array
    {
        $assoc = [];
        foreach ($rawHeaders as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2));
            $assoc[$name] = $value;
        }
        return $assoc;
    }

    private function getConfig(string $field, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue(
            self::PATH_PREFIX . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
