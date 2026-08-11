/* global TheriusSDK */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'Magento_Ui/js/model/messageList'
], function ($, Component, quote, url, globalMessageList) {
    // Both AJAX endpoints below (Controller\Checkout\Purchase / Finalize)
    // return null from createCsrfValidationException(), same pattern as
    // Controller\Webhook\Index — safe here because these are same-origin
    // browser calls gated by the shopper already being on the checkout page
    // with an active quote, not unauthenticated server-to-server calls.
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Therius_Payment/payment/therius-form',
            therius: null,
            widget: null,
            widgetMounted: false,
            mountedContainer: null,
            currentResolve: null,
            currentReject: null,
            methodType: null,
            methodPayload: null
        },

        initialize: function () {
            this._super();

            // Hide Magento's native "Place Order" button while Therius is
            // selected — only the widget's own Pay button should submit,
            // same division of responsibility as therius-checkout.js's
            // toggleNativeButton() for WooCommerce. NOT verified against a
            // live checkout render; if the selector below doesn't match this
            // Luma/theme version, the native button will stay visible and
            // double-submission handling in getData()/triggerPlaceOrder()
            // is the fallback safety net.
            var self = this;
            if (this.isChecked && this.isChecked.subscribe) {
                this.isChecked.subscribe(function (value) {
                    self.toggleNativeButton(value === self.getCode());
                });
            }

            return this;
        },

        toggleNativeButton: function (hide) {
            $('.checkout-payment-method .payment-method_' + this.getCode() + ' ~ .actions-toolbar .action.primary.checkout, .payment-method-content .actions-toolbar .action.primary.checkout').toggle(!hide);
        },

        getCode: function () {
            return 'therius';
        },

        getConfig: function () {
            return window.checkoutConfig.payment.therius || {};
        },

        /**
         * Called by the .html template's afterRender binding once the
         * payment-element container is in the DOM. Loads the Therius SDK
         * (once) and mounts the checkout widget into #therius-payment-element,
         * mirroring therius-checkout.js's mountWidget() for WooCommerce.
         */
        mountWidget: function (container) {
            var self = this;
            var config = this.getConfig();

            if (!config.clientToken) {
                return;
            }

            // Knockout's afterRender binding re-fires any time this element is
            // reinserted into the DOM — not just on the payment step's first
            // render. Stock Magento_Customer/js/customer-data refreshes
            // "sections" (and can trigger a re-render here) on tab focus, so
            // switching away from and back to the checkout tab re-fired this
            // unconditionally: $(container).empty() below wiped the DOM and a
            // brand-new CheckoutWidget instance replaced the one that may have
            // an in-flight submission running (e.g. mid-ACH-microdeposit-popup),
            // which is the "reloads once on tab refocus" behavior. Skip the
            // rebuild when the SAME container element is still mounted; still
            // rebuild when Knockout hands us a genuinely new container element
            // (e.g. switching payment methods away and back).
            if (self.widgetMounted && self.mountedContainer === container) {
                return;
            }

            this.loadSdk(config.baseUrl).done(function () {
                if (!self.therius) {
                    self.therius = new TheriusSDK.TheriusSDK({
                        clientToken: config.clientToken,
                        baseUrl: config.baseUrl
                    });
                }

                $(container).empty();

                var checkoutOptions = {
                    // Real quote id rather than the SDK's own DEMO-<timestamp>
                    // fallback (used whenever orderCode is omitted) — the
                    // real Magento order doesn't exist yet at widget-mount
                    // time (checkout hasn't been placed), so the quote id is
                    // the best available identifier; the actual purchase
                    // call server-side (Model/Therius.php's doPurchase())
                    // uses the real order increment id once one exists.
                    orderCode: 'quote-' + quote.getQuoteId(),
                    amount: parseFloat(config.amount || 0),
                    currency: config.currency || 'USD',
                    country: config.country || 'US',
                    // ddcSessionId (2nd arg) is only set when the SDK
                    // re-invokes this callback after completing a
                    // pending_ddc device-data-collection round — must be
                    // threaded through to the purchase call as
                    // threeDsSetup.sessionId (see Client::buildPurchaseBody's
                    // docblock and CheckoutOptions.onNonce in
                    // therius-sdk/src/types.ts).
                    onNonce: function (nonce, ddcSessionId) {
                        return self.handleSubmit('card', { nonce: nonce }, ddcSessionId);
                    },
                    onApm: function (apmData, ddcSessionId) {
                        return self.handleSubmit('apm', apmData, ddcSessionId);
                    },
                    onWalletToken: function (walletData, ddcSessionId) {
                        return self.handleSubmit('wallet', walletData, ddcSessionId);
                    },
                    // Fires when the widget itself finished resolving a
                    // payment outcome client-side — the ONLY notification
                    // for a real 3DS *challenge* (as opposed to the
                    // DDC/fingerprint step, which loops back through
                    // onNonce/handleSubmit above with a ddcSessionId). The
                    // widget calls the Therius API directly from the
                    // browser to resume/advance the challenge, so our
                    // server never sees that call or its outcome — without
                    // this handler both an approval and a decline after a
                    // challenge are silently dropped. Mirrors the
                    // WooCommerce reference's onActionComplete.
                    onActionComplete: function (result) {
                        self.finalizeFromActionComplete(result.paymentCode);
                    }
                };

                if (config.checkoutConfigId) {
                    checkoutOptions.checkoutConfigId = config.checkoutConfigId;
                } else {
                    checkoutOptions.config = {
                        paymentMethods: [
                            { type: 'card', label: 'Credit Card', enabled: true },
                            { type: 'pix', label: 'Pix', enabled: true },
                            { type: 'applepay', label: 'Apple Pay', enabled: true },
                            { type: 'googlepay', label: 'Google Pay', enabled: true }
                        ]
                    };
                }

                self.widget = self.therius.checkout(checkoutOptions);
                self.widget.mount(container);
                self.widgetMounted = true;
                self.mountedContainer = container;
            });
        },

        loadSdk: function (baseUrl) {
            var deferred = $.Deferred();
            if (window.TheriusSDK) {
                return deferred.resolve();
            }
            // Magento exposes RequireJS's global AMD `define()` on every
            // page. The Therius SDK's UMD bundle detects that and registers
            // itself as an anonymous AMD module instead of setting
            // `window.TheriusSDK` — since it's loaded here via a raw
            // <script> tag (not `require([...])`), it would otherwise
            // vanish into RequireJS's internal registry with no error.
            // Hiding `define` during load forces the UMD wrapper down its
            // browser-global fallback path; this is the standard pattern
            // for loading third-party UMD scripts on a RequireJS page.
            var previousDefine = window.define;
            window.define = undefined;
            $.getScript(baseUrl + '/v1/sdk/js')
                .done(function () {
                    window.define = previousDefine;
                    deferred.resolve();
                })
                .fail(function () {
                    window.define = previousDefine;
                    deferred.reject();
                });
            return deferred.promise();
        },

        /**
         * The Therius widget's Pay button is the only way to submit. Rather
         * than calling Magento's own placeOrder() immediately, this first
         * does a pre-order AJAX purchase call (Controller\Checkout\Purchase)
         * so a pending_ddc/pending_3ds actionRequired response can be handed
         * straight back to the SDK — resolving the promise with the
         * ActionRequired object is exactly what CheckoutOptions.onNonce's
         * contract expects (see therius-sdk/src/types.ts) and is what lets
         * the SDK drive its own DDC iframe / 3DS challenge redirect. Only
         * once the purchase call comes back with a genuinely final,
         * non-challenge outcome do we call placeOrder() to actually create
         * the Magento order — Model/Therius.php's doPurchase() then finds
         * that outcome already cached on the checkout session and does not
         * charge a second time.
         */
        handleSubmit: function (methodType, payload, ddcSessionId) {
            var self = this;
            return new Promise(function (resolve, reject) {
                self.callPreOrderPurchase(methodType, payload, ddcSessionId)
                    .done(function (response) {
                        if (response.actionRequired) {
                            // Not final — hand it straight back to the SDK so
                            // it can resume the challenge/DDC step itself.
                            // (See onActionComplete above for how a real 3DS
                            // *challenge*'s outcome eventually comes back to
                            // us; a pending_ddc round instead loops back
                            // through this same onNonce/onApm callback with
                            // ddcSessionId set.)
                            resolve(response.actionRequired);
                            return;
                        }
                        if (response.error) {
                            reject(new Error(response.error));
                            return;
                        }

                        self.methodType = methodType;
                        self.methodPayload = payload;

                        // Magento_Checkout/js/view/payment/default's
                        // placeOrder() returns a plain boolean, not a
                        // thenable — it manages its own async flow
                        // internally (redirects to the success page itself).
                        // resolve(true) — NOT resolve() — is deliberate: the
                        // SDK's onNonce/onApm/onWalletToken contract treats a
                        // resolved `undefined` as "the merchant didn't
                        // handle this", and falls back to its OWN internal
                        // client-side authorize() call — which is what
                        // caused a duplicate charge attempt before this fix.
                        if (self.placeOrder()) {
                            resolve(true);
                        } else {
                            reject(new Error('Payment could not be completed.'));
                        }
                    })
                    .fail(function () {
                        reject(new Error('Payment could not be completed.'));
                    });
            });
        },

        /**
         * POSTs to /therius/checkout/purchase (Controller\Checkout\Purchase)
         * — the pre-order call described in handleSubmit() above.
         */
        callPreOrderPurchase: function (methodType, payload, ddcSessionId) {
            var body = { methodType: methodType };

            if (methodType === 'card') {
                body.nonce = payload.nonce;
            } else if (methodType === 'apm') {
                body.apmData = payload;
            } else if (methodType === 'wallet') {
                body.walletData = payload;
            }
            if (ddcSessionId) {
                body.ddcSessionId = ddcSessionId;
            }

            return $.ajax({
                url: url.build('therius/checkout/purchase'),
                type: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(body)
            });
        },

        /**
         * Called from onActionComplete once the widget has resolved a real
         * 3DS *challenge* entirely client-side. POSTs to
         * /therius/checkout/finalize (Controller\Checkout\Finalize), which
         * re-derives the authoritative outcome via GET /payment/inquiry
         * rather than trusting the widget-supplied paymentCode directly,
         * then — on success — places the Magento order the same way
         * handleSubmit() does. Mirrors the WooCommerce reference's
         * finalizeFromActionComplete().
         */
        finalizeFromActionComplete: function (paymentCode) {
            var self = this;
            if (!paymentCode) {
                return;
            }

            $.ajax({
                url: url.build('therius/checkout/finalize'),
                type: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ paymentCode: paymentCode })
            }).done(function (response) {
                if (response.error) {
                    globalMessageList.addErrorMessage({ message: response.error });
                    return;
                }
                // methodType/methodPayload are already set from the earlier
                // handleSubmit() call on this same attempt — getData() just
                // needs to produce a valid additional_data payload;
                // Model/Therius.php's doPurchase() uses the session-cached
                // outcome Finalize just stored, not these values, so which
                // method type they describe no longer matters for charging.
                self.placeOrder();
            }).fail(function () {
                globalMessageList.addErrorMessage({ message: 'Payment could not be completed.' });
            });
        },

        getData: function () {
            var additionalData = {
                therius_method: this.methodType || 'card'
            };

            if (this.methodType === 'card') {
                additionalData.therius_nonce = this.methodPayload ? this.methodPayload.nonce : '';
            } else if (this.methodType === 'apm') {
                additionalData.therius_apm_data = JSON.stringify(this.methodPayload || {});
            } else if (this.methodType === 'wallet') {
                additionalData.therius_wallet_data = JSON.stringify(this.methodPayload || {});
            }

            return {
                'method': this.getCode(),
                'additional_data': additionalData
            };
        }
    });
});
