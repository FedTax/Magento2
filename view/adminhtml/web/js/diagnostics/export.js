/**
 * Taxcloud_Magento2
 *
 * Generation dialog for the diagnostics bundle, shared by the tax
 * configuration button and the order view button. States plainly what the
 * bundle will contain before anything is generated, then submits a real form
 * POST (with the admin form key) so the browser downloads the ZIP.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/modal'
], function ($, $t) {
    'use strict';

    function option(value, label, selected) {
        return $('<option>').attr('value', value).prop('selected', !!selected).text(label);
    }

    function buildContent(config) {
        var content = $('<div class="taxcloud-diagnostics-dialog">');

        content.append($('<p>').text(config.orderId
            ? $t('The file describes this order: its totals, items and TICs, addresses, invoices and credit memos, the TaxCloud log lines recorded for it, and your TaxCloud settings for its store.')
            : $t('The file describes your TaxCloud setup: settings at every scope, Magento tax rules, installed extensions, server details, recent TaxCloud log entries, and a live connection test.')));
        content.append($('<p>').append($('<strong>').text(
            $t('Unless you mask customer details below, the file contains customer order data: names, street addresses, email addresses and phone numbers.')
        )));
        content.append($('<p>').text(
            $t('Your TaxCloud API credentials are never included, whichever options you choose.')
        ));

        var redact = $('<input type="checkbox" name="redact_pii" value="1">');
        content.append($('<div class="admin__field admin__field-option">').append(
            $('<label class="admin__field-label">').append(redact, ' ', $('<span>').text(
                $t('Mask customer details (names, street addresses, emails, phone numbers). City, state, ZIP and TICs stay visible, because tax depends on them.')
            ))
        ));

        var windowSelect = $('<select class="admin__control-select" name="log_window">').append(
            option('standard', $t('Last 10 MB or 7 days of log (recommended)'), true),
            option('extended', $t('Last 50 MB or 30 days of log')),
            option('maximum', $t('Last 200 MB or 90 days of log'))
        );
        content.append($('<div class="admin__field">').append(
            $('<label class="admin__field-label">').text($t('Log window')),
            $('<div class="admin__field-control">').append(windowSelect)
        ));

        var probe = $('<input type="checkbox" name="probe" value="1" checked>');
        content.append($('<div class="admin__field admin__field-option">').append(
            $('<label class="admin__field-label">').append(probe, ' ', $('<span>').text(
                $t('Test the connection: make a live tax calculation and address check against TaxCloud from this server, using a fixed test address. Nothing is recorded as a sale.')
            ))
        ));

        content.append($('<p class="note">').text(
            $t('The file downloads to your computer. Attach it to your support ticket yourself; nothing is sent to TaxCloud automatically.')
        ));

        return {content: content, redact: redact, windowSelect: windowSelect, probe: probe};
    }

    function submit(config, dialog) {
        var fields = {
            form_key: window.FORM_KEY,
            website: config.website || '',
            store: config.store || '',
            order_id: config.orderId || '',
            redact_pii: dialog.redact.is(':checked') ? '1' : '0',
            log_window: dialog.windowSelect.val(),
            probe: dialog.probe.is(':checked') ? '1' : '0'
        };
        var form = $('<form method="post">').attr('action', config.url).hide();

        $.each(fields, function (name, value) {
            form.append($('<input type="hidden">').attr('name', name).val(value));
        });
        $('body').append(form);
        form[0].submit();
        setTimeout(function () {
            form.remove();
        }, 1000);
    }

    return function (config, element) {
        var dialog = buildContent(config);

        dialog.content.modal({
            title: $t('Download TaxCloud diagnostics'),
            type: 'popup',
            buttons: [{
                text: $t('Cancel'),
                class: 'action-secondary',
                click: function () {
                    this.closeModal();
                }
            }, {
                text: $t('Generate and download'),
                class: 'action-primary',
                click: function () {
                    submit(config, dialog);
                    this.closeModal();
                }
            }]
        });

        $(element).on('click', function (event) {
            event.preventDefault();
            dialog.content.modal('openModal');
        }).attr('data-taxcloud-diagnostics-ready', '1');
    };
});
