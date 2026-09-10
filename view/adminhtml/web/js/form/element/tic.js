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
 * TIC autocomplete for UI form fields — the product and category attributes.
 *
 * A thin adapter: it extends Magento's form element so the value binds to the
 * form's data provider (and the scope / "Use Default Value" chrome keeps
 * working), and mixes in the shared behaviour for everything else.
 */
define([
    'underscore',
    'Magento_Ui/js/form/element/abstract',
    'Taxcloud_Magento2/js/tic/behaviour'
], function (_, Abstract, behaviour) {
    'use strict';

    return Abstract.extend(_.extend({}, behaviour, {
        defaults: _.extend({}, behaviour.defaults, {
            // elementTmpl only — `template` stays the framework's field
            // wrapper, which supplies the label, the scope label and the
            // "Use Default Value" chrome. Overriding it would render the
            // control naked.
            elementTmpl: 'Taxcloud_Magento2/form/element/tic',
            listens: {
                value: 'onValueChange'
            }
        }),

        /**
         * @returns {Object} chainable
         */
        initialize: function () {
            this._super();

            return this.initTic();
        }
    }));
});
