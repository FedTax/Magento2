/**
 * Taxcloud_Magento2
 *
 * Exemption-certificate panel on the customer edit page.
 *
 * Fetches on demand rather than with the page: reading certificates is a live
 * call to TaxCloud, and an administrator changing an address should not wait on
 * it — or be unable to load the page when TaxCloud is unreachable.
 *
 * The distinction this UI works hardest to preserve is between "this customer
 * holds no certificates" and "we could not ask". They render identically as an
 * empty table and mean opposite things: the first is a customer who is simply
 * not exempt, the second is a customer who may be exempt and is about to be
 * taxed anyway. The endpoints keep them apart; so does this.
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
            addForm = root.find('[data-role="add-form"]');

        /**
         * @param {String} message
         * @param {String} [tone] 'error' when this is a failure rather than a fact
         */
        function say(message, tone) {
            status.text(message).css('color', tone === 'error' ? '#e22626' : '');
        }

        function escapeHtml(value) {
            return $('<div>').text(value === null || value === undefined ? '' : value).html();
        }

        /**
         * A certificate's covered states, or an explicit note when it covers
         * none — an empty cell would read as missing data rather than as a
         * certificate that exempts nowhere.
         */
        function statesCell(certificate) {
            if (!certificate.states || !certificate.states.length) {
                return '<em>' + escapeHtml($t('covers no states')) + '</em>';
            }

            return escapeHtml(certificate.states.join(', '));
        }

        /**
         * Detail v3 cannot supply is absent rather than blank, so show a dash
         * instead of an empty cell: the certificate may well carry the value,
         * this transport just cannot report it.
         */
        function detail(certificate, key) {
            var value = certificate.detail && certificate.detail[key];

            return value ? escapeHtml(value) : '&mdash;';
        }

        function render(certificates) {
            rows.empty();

            if (!certificates.length) {
                table.hide();

                return;
            }

            certificates.forEach(function (certificate) {
                var disabled = certificate.disabled
                    ? ' <em>(' + escapeHtml($t('disabled')) + ')</em>'
                    : '';

                rows.append(
                    '<tr>' +
                    '<td><code>' + escapeHtml(certificate.certificateId) + '</code>' + disabled + '</td>' +
                    '<td>' + statesCell(certificate) + '</td>' +
                    '<td>' + detail(certificate, 'purchaserName') + '</td>' +
                    '<td>' + detail(certificate, 'reason') + '</td>' +
                    '<td><button type="button" class="action-secondary" data-delete="' +
                    escapeHtml(certificate.certificateId) + '">' + escapeHtml($t('Delete')) + '</button></td>' +
                    '</tr>'
                );
            });

            table.show();
        }

        function load() {
            say($t('Reading certificates from TaxCloud…'));

            $.get(config.endpoints.list).done(function (response) {
                if (!response.success) {
                    // Never fall through to an empty table: that would report a
                    // failed read as "this customer is not exempt".
                    table.hide();
                    say(response.message || $t('Could not read certificates from TaxCloud.'), 'error');

                    return;
                }

                root.find('[data-role="identity"]').text(response.identity || '—');
                root.find('[data-role="identity-note"]').text(
                    response.identityIsDefault
                        ? $t('(the Magento customer ID)')
                        : $t('(set explicitly on this customer)')
                );

                render(response.certificates || []);

                if (!response.certificates || !response.certificates.length) {
                    say($t(
                        'TaxCloud holds no certificates under this ID. If this customer has one, ' +
                        'check the TaxCloud Customer ID above matches what was entered in the TaxCloud portal.'
                    ));
                } else {
                    say('');
                }
            }).fail(function () {
                table.hide();
                say($t('Could not reach Magento to read certificates.'), 'error');
            });
        }

        function collectForm() {
            var payload = {};

            addForm.find('[data-field]').each(function () {
                payload[$(this).data('field')] = $(this).val();
            });

            return payload;
        }

        root.on('click', '[data-role="refresh"]', function () {
            say($t('Discarding cached certificates…'));
            $.post(config.endpoints.refresh, {
                form_key: window.FORM_KEY
            }).always(load);
        });

        root.on('click', '[data-role="show-add"]', function () {
            addForm.show();
        });

        root.on('click', '[data-role="cancel"]', function () {
            addForm.hide();
        });

        root.on('click', '[data-role="save"]', function () {
            say($t('Creating certificate…'));

            $.post(config.endpoints.add, {
                form_key: window.FORM_KEY,
                certificate: collectForm()
            }).done(function (response) {
                if (!response.success) {
                    say(response.message, 'error');

                    return;
                }

                addForm.hide();
                load();
            }).fail(function () {
                say($t('Could not reach Magento to create the certificate.'), 'error');
            });
        });

        root.on('click', '[data-delete]', function () {
            var certificateId = $(this).data('delete');

            // Deleting is not reversible: TaxCloud offers no way to restore a
            // certificate, and the customer stops being exempt immediately.
            if (!window.confirm($t('Delete this certificate? It cannot be undone.'))) {
                return;
            }

            say($t('Deleting…'));

            $.post(config.endpoints['delete'], {
                form_key: window.FORM_KEY,
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    say(response.message, 'error');

                    return;
                }

                load();
            }).fail(function () {
                say($t('Could not reach Magento to delete the certificate.'), 'error');
            });
        });

        load();
    };
});
