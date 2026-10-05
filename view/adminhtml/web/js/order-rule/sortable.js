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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * Drag-to-reorder for the order rules list. Every drop posts the full id
 * order, so the server never has to reconcile partial moves; a failed save
 * puts the rows back where they were.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'jquery-ui-modules/sortable'
], function ($, $t, alert, confirm) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $body = $root.find('[data-role="taxcloud-order-rules"]');

        function currentOrder() {
            return $body.children('tr').map(function () {
                return $(this).data('rule-id');
            }).get();
        }

        $body.sortable({
            axis: 'y',
            handle: '[data-role="drag-handle"]',
            items: '> tr',
            tolerance: 'pointer',
            helper: function (event, $row) {
                // Keep cell widths while the row is lifted out of the table.
                $row.children().each(function () {
                    $(this).width($(this).width());
                });
                return $row;
            },
            start: function (event, ui) {
                ui.item.data('taxcloud-before', currentOrder());
            },
            update: function (event, ui) {
                var before = ui.item.data('taxcloud-before');

                $.ajax({
                    url: config.saveUrl,
                    type: 'POST',
                    dataType: 'json',
                    showLoader: true,
                    data: {
                        'form_key': window.FORM_KEY,
                        'rule_ids': currentOrder()
                    }
                }).fail(function (xhr) {
                    var message = (xhr.responseJSON && xhr.responseJSON.message) ||
                        $t('The new order could not be saved. Reload the page and try again.');

                    // Restore the previous order so the screen matches what is stored.
                    $.each(before, function (index, id) {
                        $body.append($body.children('tr[data-rule-id="' + id + '"]'));
                    });
                    alert({content: message});
                });
            }
        });

        $root.on('submit', '.taxcloud-order-rules__delete', function (event) {
            var form = this;

            if ($(form).data('confirmed')) {
                return true;
            }
            event.preventDefault();
            confirm({
                content: $(form).data('confirm'),
                actions: {
                    confirm: function () {
                        $(form).data('confirmed', true);
                        form.submit();
                    }
                }
            });

            return false;
        });
    };
});
