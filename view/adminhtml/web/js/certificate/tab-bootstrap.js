/**
 * Taxcloud_Magento2
 *
 * Starts the certificate panel once it exists.
 *
 * The panel lives in a customer tab, which knockout injects long after
 * Magento's own component bootstrap has run — and the stock tab template
 * renders through knockout's native `html` binding, which unlike Magento's
 * `bindHtml` never calls mage.apply(). Neither text/x-magento-init nor
 * data-mage-init is ever processed there.
 *
 * This module is initialised from the page itself, where the normal bootstrap
 * does reach it, and waits for the panel to appear.
 *
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */
define([
    'jquery',
    'Taxcloud_Magento2/js/certificate/admin-certificates',
    'domReady!'
], function ($, panel) {
    'use strict';

    var SELECTOR = '[data-taxcloud-certificates][data-taxcloud-config]';

    /**
     * @return {Boolean} whether the panel has been found and started
     */
    function boot() {
        var element = document.querySelector(SELECTOR),
            config;

        if (!element) {
            return false;
        }

        // Guard against a second start: the observer can fire repeatedly while
        // the form renders, and two live panels would each issue their own
        // writes against TaxCloud.
        if ($(element).data('taxcloudBooted')) {
            return true;
        }

        try {
            config = JSON.parse(element.getAttribute('data-taxcloud-config'));
        } catch (e) {
            return true;
        }

        $(element).data('taxcloudBooted', true);
        panel(config, element);

        return true;
    }

    return function () {
        if (boot()) {
            return;
        }

        var observer = new MutationObserver(function () {
            if (boot()) {
                observer.disconnect();
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    };
});
