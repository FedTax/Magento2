/**
 * Taxcloud_Magento2
 *
 * Exemption certificates in My Account.
 *
 * Fetches on demand rather than with the page: reading certificates is a live
 * call to TaxCloud, and a customer's account page should not fail to load
 * because a third party is slow.
 *
 * The distinction it works hardest to keep is between "you have no
 * certificates" and "we could not ask TaxCloud". Both render as an empty table
 * and mean opposite things — and a customer told the first when the second is
 * true will go and create a duplicate of a certificate they already hold.
 *
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    return function (config, element) {
        var root = $(element),
            status = root.find('[data-role="status"]'),
            table = root.find('[data-role="certificate-table"]'),
            rows = root.find('[data-role="certificate-rows"]'),
            addForm = root.find('[data-role="add-form"]'),
            addToolbar = root.find('[data-role="add-toolbar"]');

        function say(message, tone) {
            if (!message) {
                status.empty();

                return;
            }

            status.html(
                $('<div>')
                    .addClass('message ' + (tone === 'error' ? 'error' : 'notice'))
                    .append($('<div>').text(message))
            );
        }

        function escapeHtml(value) {
            return $('<div>').text(value === null || value === undefined ? '' : value).html();
        }

        function render(certificates) {
            rows.empty();

            if (!certificates.length) {
                table.hide();

                return;
            }

            certificates.forEach(function (certificate) {
                rows.append(
                    '<tr>' +
                    '<td class="col">' + escapeHtml((certificate.states || []).join(', ')) + '</td>' +
                    '<td class="col">' + escapeHtml(certificate.purchaserName || '—') + '</td>' +
                    '<td class="col">' + escapeHtml(certificate.reason || '—') + '</td>' +
                    '<td class="col"><a href="#" data-delete="' + escapeHtml(certificate.certificateId) + '">' +
                    escapeHtml($t('Remove')) + '</a></td>' +
                    '</tr>'
                );
            });

            table.show();
        }

        function load() {
            $.get(config.endpoints.list).done(function (response) {
                if (!response.success) {
                    table.hide();
                    // Not an empty list — see the module docblock.
                    say(response.message || $t('We could not load your certificates just now.'), 'error');

                    return;
                }

                render(response.certificates || []);
                addToolbar.toggle(Boolean(response.mayCreate));

                if (!response.certificates || !response.certificates.length) {
                    say(
                        response.mayCreate
                            ? $t('You have no exemption certificates yet.')
                            : $t('You have no exemption certificates. Contact us if you believe you should be tax exempt.')
                    );
                } else {
                    say('');
                }
            }).fail(function () {
                table.hide();
                say($t('We could not load your certificates just now.'), 'error');
            });
        }

        function collectForm() {
            var payload = {};

            addForm.find('[data-field]').each(function () {
                payload[$(this).data('field')] = $(this).val();
            });

            return payload;
        }

        root.on('click', '[data-role="show-add"]', function () {
            addForm.show();
            addToolbar.hide();
        });

        root.on('click', '[data-role="cancel"]', function (event) {
            event.preventDefault();
            addForm.hide();
            addToolbar.show();
        });

        root.on('click', '[data-role="save"]', function () {
            say($t('Saving your certificate…'));

            $.post(config.endpoints.add, {
                form_key: $.mage.cookies.get('form_key'),
                certificate: collectForm()
            }).done(function (response) {
                if (!response.success) {
                    say(response.message, 'error');

                    return;
                }

                addForm.hide();
                load();
            }).fail(function () {
                say($t('We could not save your certificate just now.'), 'error');
            });
        });

        root.on('click', '[data-delete]', function (event) {
            var certificateId = $(this).data('delete');

            event.preventDefault();

            // Irreversible: TaxCloud cannot restore a deleted certificate, and
            // the customer stops being exempt from the next order onwards.
            if (!window.confirm($t('Remove this certificate? You will be charged tax on future orders unless you add another.'))) {
                return;
            }

            $.post(config.endpoints['delete'], {
                form_key: $.mage.cookies.get('form_key'),
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    say(response.message, 'error');

                    return;
                }

                load();
            }).fail(function () {
                say($t('We could not remove that certificate just now.'), 'error');
            });
        });

        load();
    };
});
