/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * TIC autocomplete for Stores → Configuration — the Default TIC and Shipping
 * TIC fields.
 *
 * The other adapter extends Magento's form element, which imports its value
 * from a form data provider. The configuration form has no such provider, so
 * this one extends the plain UI element and owns its value observable
 * directly, writing through to the real config input that the configuration
 * form posts. Everything the admin actually sees and does comes from the same
 * shared behaviour as the form fields.
 */
define([
    'underscore',
    'uiElement',
    'Taxcloud_Magento2/js/tic/behaviour'
], function (_, Element, behaviour) {
    'use strict';

    return Element.extend(_.extend({}, behaviour, {
        defaults: _.extend({}, behaviour.defaults, {
            template: 'Taxcloud_Magento2/form/element/tic',
            // The name/id of the real config input this control stands in for.
            inputName: '',
            uid: '',
            value: '',
            disabled: false,
            focused: false,
            links: {
                value: false
            }
        }),

        /**
         * @returns {Object} chainable
         */
        initialize: function () {
            this._super();

            return this.initTic();
        },

        /**
         * value is a genuine observable here rather than a provider import,
         * because there is no provider to import it from.
         *
         * @returns {Object} chainable
         */
        initObservable: function () {
            return this._super().observe(['value', 'disabled', 'focused']);
        }
    }));
});
