# Therius Payment Orchestration for Magento 2

This module integrates the Therius Payment Orchestration Platform with your Magento 2 store, allowing you to accept secure, PCI-compliant payments while taking advantage of smart routing and multi-PSP capabilities.

## Installation

### Composer (recommended)
1. Copy this `therius-plugin-magento` folder somewhere Composer can reach it (e.g. a local path repository), or place it directly at `app/code/Therius/Payment` in your Magento install (skip Composer autoload registration in that case — Magento's component registration in `registration.php` is enough).
2. If installing via Composer with a local path repo, add to your Magento project's `composer.json`:
   ```json
   "repositories": {
       "therius-payment": { "type": "path", "url": "../therius-plugin-magento" }
   }
   ```
   then `composer require therius/module-payment:*`.
3. Run:
   ```bash
   bin/magento module:enable Therius_Payment
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

### Manual (copy into app/code)
1. Copy this folder to `app/code/Therius/Payment/` in your Magento installation.
2. Run the same four `bin/magento` commands listed above.

## Configuration & Environments (Sandbox / Production)

The module supports both **Sandbox (Test)** and **Production (Live)** environments out of the box.

1. Navigate to **Stores → Configuration → Sales → Payment Methods → Therius Payments**.
2. Set **Enabled** to Yes.
3. **Environment setup**:
   - **Sandbox/Test Mode**: Set **Test Mode** to Yes. Enter your Therius Sandbox keys into **Test Publishable Key** and **Test Private Key**. In test mode the module routes transactions to `https://api-sandbox.therius.io`.
   - **Production/Live Mode**: Set **Test Mode** to No. Enter your live keys into **Live Publishable Key** and **Live Private Key**. Transactions route to `https://api.therius.io`.
4. Save the configuration, then run `bin/magento cache:flush` (Magento config values are cached).

## Webhooks (required for reliable order status)

The `/v1/payment/purchase` call tells you the outcome at the moment of charge, but capture confirmation, refunds, cancellations, and chargebacks all happen **after** that response — the module only learns about them if you configure a webhook.

1. In the Therius Dashboard, go to **Developers → Webhooks** and add an endpoint pointing at:
   ```
   https://your-store.com/therius/webhook/index
   ```
   Configure one webhook in **Sandbox** mode and one in **Production** mode if you use both.
2. Copy the signing secret shown for each environment into the module's **Test Webhook Signing Secret** / **Live Webhook Signing Secret** fields.
3. Subscribe to at least: `payment.captured`, `payment.refused`, `payment.refunded`, `payment.cancelled`, `payment.chargeback`.

Without this configured, orders left `pending_payment` (async fraud/issuer review) will never resolve, and refunds/chargebacks issued from the Therius dashboard won't be reflected on the Magento order. Every incoming webhook call is verified against the signing secret (`X-Therius-Signature`, HMAC-SHA256) before anything is applied — unsigned or mis-signed requests are rejected with `401`.

## Design notes

- The payment method (`Model/Therius.php`) extends Magento's `AbstractMethod` rather than using the Payment Gateway command-pool pattern. Therius does its own authorize/capture/pending decisioning server-side in a single `/v1/payment/purchase` call and confirms the final state asynchronously via webhook — there's no multi-step gateway command sequence to model. The real purchase call lives in a shared private `doPurchase()`, called from **both** `authorize()` and `capture()` (each guarded by an idempotency check on `therius_payment_id`) rather than just one — confirmed live that Magento's default `authorize_capture` payment_action calls only `capture()` in this Magento version (no `SaleOperationInterface` on this class), not both back-to-back as older-version docs/tutorials describe; see the `plugin-developer` skill's Section 10 for the full story.
- The order-to-webhook matching in `Controller/Webhook/Index.php` scans `sales_order_payment.additional_information` for a stored `therius_payment_id` because that column is a serialized blob, not natively filterable in SQL. This is functionally equivalent to the WooCommerce reference's `wc_get_orders(meta_key/meta_value)` lookup but **has not been verified against a live database** — on a store with very large order volume this table scan should be replaced with a small dedicated lookup table before going to production.

## Known gaps / not yet verified

This module was built and code-reviewed against the WooCommerce reference plugin's integration contract. **Update 2026-08-10**: a full real-browser checkout run (real `repo.magento.com` Marketplace keys, a real Therius sandbox key pair, real product/cart/shipping) surfaced and fixed **seven distinct real application bugs**, each one previously invisible because nothing had ever been run against a live checkout before. The widget now renders and mounts correctly end-to-end.

**Fixed, in the order they were found while chasing "no checkout widget, no errors":**

1. **Encrypted config fields never decrypted.** `test_private_key`/`private_key`/`test_webhook_secret`/`webhook_secret` are `type="obscure"` with `backend_model=Encrypted` in `system.xml`, so Magento encrypts them on save — but `Model/Api/Client.php` read them via plain `scopeConfig->getValue()` with no decrypt step, sending raw ciphertext as the `Authorization: Bearer` token on every call. Fixed by injecting `EncryptorInterface` into `Client` and decrypting in `getPrivateKey()`/`getWebhookSecret()`.
2. **`Model\Ui\ConfigProvider` was declared in the wrong DI scope.** It lived in the global `etc/di.xml`, but `Magento\Checkout\Model\CompositeConfigProvider`'s array-argument merge only picks up area-scoped declarations for this class (confirmed by diffing against `Magento_OfflinePayments`, which correctly uses `etc/frontend/di.xml`). The provider was silently never merged into the real checkout page's `window.checkoutConfig` — `Model/Ui/ConfigProvider.php` never even got instantiated on a real request, confirmed via a temporary debug controller. Moved to `etc/frontend/di.xml`.
3. **No `rendererList.push()` registration shim.** Magento's checkout doesn't render a payment method just because it's in the jsLayout tree — `Magento_Checkout/js/view/payment/list.js` only creates a visible renderer for a method code present in a *separate* JS registry (`Magento_Checkout/js/model/payment/renderer-list`). The layout XML pointed `component` directly at `therius-method.js` (the real renderer), skipping the registration step entirely. Added `view/frontend/web/js/view/payment/therius.js` (mirroring `Magento_OfflinePayments/js/view/payment/offline-payments.js`'s exact pattern) and repointed the layout XML at it.
4. **Missing CSP whitelist.** Magento 2.4.4+ ships Content-Security-Policy on by default; `api.therius.io`/`api-sandbox.therius.io` weren't whitelisted for `script-src`, `connect-src`, `frame-src`, or `img-src`, so the SDK script, its XHR calls, and its rendered payment-method icons were all silently blocked. Added `etc/csp_whitelist.xml`.
5. **SDK/RequireJS UMD collision.** The Therius SDK's UMD bundle detects `window.define`/`define.amd` (which Magento's RequireJS always exposes) and registers itself as an anonymous AMD module instead of setting `window.TheriusSDK` — since the SDK is loaded via a raw `<script>` tag (`$.getScript`), not `require([...])`, it silently vanished into RequireJS's internal registry. Fixed in `therius-method.js`'s `loadSdk()` by temporarily hiding `window.define` during the script load, forcing the UMD wrapper's browser-global fallback path — the standard pattern for loading third-party UMD scripts on a RequireJS page.
6. **`createSdkSession` always failed on a fresh guest checkout.** The real API requires `country`, but the quote's billing address is empty at initial page load (before the shopper enters anything), and `window.checkoutConfig` is only populated once, at that load — so the very first session attempt always got rejected, with no exception anywhere (`Client::post()` never throws on non-2xx). Fixed in `ConfigProvider.php` by falling back to the store's configured default country for the *session-bootstrap* call only (the config's returned `country` field, and the widget's own billing form, are untouched).
7. **`placeOrder()` is not a thenable.** `triggerPlaceOrder()` called `self.placeOrder().done(...).fail(...)`, but `Magento_Checkout/js/view/payment/default`'s `placeOrder()` returns a plain boolean and manages its own async success/redirect flow internally — the `.done` call threw synchronously (`self.placeOrder(...).done is not a function`), shown to the shopper as an error message even though Magento's own order placement had already succeeded underneath it. Fixed to treat the boolean return value as the actual signal.
8. **Amount sent in major units, not minor units.** `checkoutOptions.amount` (and the real `/v1/payment/authorization` request body it drives) needs integer minor units (cents) — confirmed both by the SDK bundle's own `_formatAmount()` dividing by 100 for display, and by a real `422` rejecting a raw float. `ConfigProvider.php` was sending `(float) $quote->getGrandTotal()` directly. Fixed using the existing `Client::toMinorUnits()` helper (same zero-decimal-currency logic already used server-side for the purchase call).

Also reported upstream (not a plugin bug, but discovered via this same debugging session): the real `/v1/payment/authorization` `422` response leaked raw Go error text (`"json: cannot unmarshal number 24.99 into Go struct field amountField.amount.value of type int64"`) — struct field names and the implementation language, straight to the browser. Fixed in `therius-public-api/handlers_payment.go` (generic sanitized messages, real error still logged server-side with trace_id) and pushed to `D:\therius\code` — but that fix lives on the platform side and needs deploying to the actual sandbox VPS to take effect there.

**Three more real bugs found once payments actually started reaching the API** (full detail and the generalizable lessons for any future platform are in the `plugin-developer` skill's Section 10 — this is the condensed version):

9. **Declined with `MISSING_FIELD: orderInformation.billTo.administrativeArea`.** Confirmed CyberSource (at least) hard-requires the billing state/region on a card purchase. Neither `Model/Therius.php`'s server-side purchase call nor the widget's `checkoutOptions` sent any billing address at all. Fixed by adding `card.nonceData.cardAddress` (address1/2, city, `getRegionCode()`, country, postal code — from `$order->getBillingAddress()`) to `doPurchase()`'s request body.
10. **The SDK was silently making its *own* redundant client-side authorization call.** Root cause of bug 9 actually showing up as a live browser XHR: `onNonce`/`onApm`/`onWalletToken` resolving to `undefined` (bare `resolve()`) is the SDK's documented signal for "the merchant didn't handle this — I'll call `sdk.authorize()` myself" (see `therius-sdk/src/types.ts`'s `CheckoutOptions` docblock). Since Magento's SPA-style checkout keeps the JS context alive across `placeOrder()` (unlike WooCommerce's full-page form POST, which destroys the context before this matters), the promise actually resolved, triggering the SDK's own incomplete client-side attempt *in addition to* our correct server-side one — two independent payment attempts, no error linking them. Fixed by resolving `true` instead of nothing in `triggerPlaceOrder()`.
11. **Magento's default `authorize_capture` payment_action calls only `capture()`, not `authorize()`+`capture()`.** The purchase logic lived entirely in `authorize()` (following older-Magento-version tutorials/comments), which this Magento version simply never calls for a method that doesn't implement `SaleOperationInterface` — confirmed via `Magento\Sales\Model\Order\Payment::place()`'s actual dispatch code. Every order "succeeded" in Magento without ever calling the Therius API — `capture()`'s deliberate no-op just returned cleanly, no exception anywhere. Fixed by moving the real logic into a shared `doPurchase()` called from both `authorize()` and `capture()`, guarded by the existing idempotency key so a genuine double-call can't double-charge. Also fixed in the same pass: the widget now passes a real `checkoutOptions.orderCode` (`quote-<id>`) instead of leaving it unset, which was producing `DEMO-<timestamp>` codes on the Therius dashboard side.

**Still not fully verified end-to-end:** a real payment attempt reaching Therius and appearing correctly in the merchant dashboard, after all eleven fixes above. Everything up through order placement (widget renders, card form works, order submits to Magento, `capture()` now actually calls the real API) is confirmed; the final "does the payment show up correctly on the Therius side" check was in progress when this was last updated.

`toggleNativeButton()`'s theme-dependent CSS selector for hiding Magento's native "Place Order" button was separately confirmed **working** against the Luma theme during this session (native button correctly hidden) — no longer purely a guess for at least this one theme.
