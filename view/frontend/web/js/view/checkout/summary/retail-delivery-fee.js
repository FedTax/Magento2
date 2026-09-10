/**
 * Taxcloud_Magento2
 *
 * Colorado Retail Delivery Fee line in the cart/checkout totals.
 *
 * Renders only when the taxcloud_rdf total segment is present and non-zero —
 * the segment is emitted by the quote total collector solely for eligible
 * Colorado deliveries, so ineligible carts show nothing.
 */
define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals'
], function (Component, quote, totals) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Taxcloud_Magento2/checkout/summary/retail-delivery-fee'
        },

        totals: quote.getTotals(),

        /**
         * The taxcloud_rdf segment, or null when the fee does not apply.
         *
         * @returns {Object|null}
         */
        getSegment: function () {
            return totals.getSegment('taxcloud_rdf');
        },

        /**
         * @returns {Boolean}
         */
        isDisplayed: function () {
            var segment = this.getSegment();

            return !!segment && Number(segment.value) > 0;
        },

        /**
         * @returns {String}
         */
        getValue: function () {
            var segment = this.getSegment();

            return this.getFormattedPrice(segment ? segment.value : 0);
        },

        /**
         * @returns {String}
         */
        getTitle: function () {
            var segment = this.getSegment();

            return segment ? segment.title : '';
        }
    });
});
