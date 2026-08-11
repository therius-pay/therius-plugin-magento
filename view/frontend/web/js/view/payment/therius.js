/**
 * Registration shim for the Therius payment method renderer.
 *
 * Magento's checkout doesn't render a payment method renderer just because
 * it's present in the jsLayout tree under checkout.payment.renders — that
 * node only exists to get this file *loaded*. The component that actually
 * builds visible DOM (Magento_Checkout/js/view/payment/list.js) looks up
 * renderers from a *separate* global registry
 * (Magento_Checkout/js/model/payment/renderer-list) keyed by method code,
 * and only creates a renderer for a method if it finds a matching entry
 * there — with no error if it doesn't. This file's only job is to push
 * that entry; the actual renderer is therius-method.js, loaded lazily by
 * list.js once a matching cart payment method is found. Mirrors
 * Magento_OfflinePayments/js/view/payment/offline-payments.js exactly.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'therius',
        component: 'Therius_Payment/js/view/payment/method-renderer/therius-method'
    });

    return Component.extend({});
});
