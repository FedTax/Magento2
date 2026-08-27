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
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'mage/loader',
    'mage/validation'
], function ($, $t, uiAlert) {
    'use strict';

    return function (config, element) {
        var root = $(element),
            status = root.find('[data-role="status"]'),
            table = root.find('[data-role="certificate-table"]'),
            rows = root.find('[data-role="certificate-rows"]'),
            addForm = root.find('[data-role="add-form"]'),
            addToolbar = root.find('[data-role="add-toolbar"]'),
            pending = 0;

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

        /**
         * Magento's loading mask, plus disabling the controls behind it.
         *
         * The mask alone would be decoration. The reason the buttons are
         * disabled is that creating and deleting certificates are live,
         * non-idempotent writes to TaxCloud: a second click while the first
         * request is in flight files a duplicate certificate, or deletes one
         * and then fails on the retry. The status text this replaces sits at
         * the top of the panel, which is off-screen when the Save button at
         * the foot of the form is what you just clicked.
         *
         * Counted rather than boolean so overlapping requests do not have the
         * first one to finish tear the mask off the others.
         *
         * @param {Boolean} isBusy
         */
        function busy(isBusy) {
            var wasBusy = pending > 0;

            pending = Math.max(0, pending + (isBusy ? 1 : -1));

            if ((pending > 0) !== wasBusy) {
                $('body').trigger(pending > 0 ? 'processStart' : 'processStop');
            }

            root.find('button').prop('disabled', pending > 0);
        }

        /**
         * Report a failed write.
         *
         * A modal rather than inline text because the inline status renders at
         * the top of the panel while the Save button sits at the foot of a long
         * form: a validation error arrived off-screen, which reads as a save
         * that silently did nothing.
         *
         * The inline copy is kept as well as the modal, so the reason is still
         * legible while the form is being corrected after the modal is
         * dismissed.
         *
         * @param {String} message
         * @param {String} title
         */
        function failed(message, title) {
            say(message, 'error');
            uiAlert({
                title: title,
                content: message
            });
        }

        /**
         * Why a read failed below the application layer.
         *
         * The controller answers TaxCloud problems as JSON, so reaching here
         * means the request never got that far. Told apart because a signed-out
         * session — the common one, and the one the customer can fix — used to
         * read the same as a TaxCloud outage.
         *
         * @param {Object} xhr
         * @return {String}
         */
        function readFailure(xhr) {
            var status = xhr && xhr.status;

            if (status === 200 || status === 401 || status === 403) {
                return $t('Please sign in again to see your certificates.');
            }

            return $t('We could not load your certificates just now. Please try again.');
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
            }).fail(function (xhr) {
                table.hide();
                say(readFailure(xhr), 'error');
            });
        }

        function collectForm() {
            var payload = {};

            addForm.find('[data-field]').each(function () {
                payload[$(this).data('field')] = $(this).val() || '';
            });

            return payload;
        }


        /**
         * Select-all / clear for the states multiselect.
         *
         * An all-states certificate has to be expressed by selecting them all,
         * not by leaving the list blank: TaxCloud records the list but never
         * enforces it, so a blank list means nothing to them and this module
         * would have to invent a scope the merchant never granted. One click
         * beats fifty, and the certificate then says what it covers.
         */
        root.on('click', '[data-role="states-all"]', function (event) {
            event.preventDefault();
            var select = addForm.find('[data-field="states"]');

            select.find('option').prop('selected', true);
            select.trigger('change');
        });

        root.on('click', '[data-role="states-none"]', function (event) {
            event.preventDefault();
            var select = addForm.find('[data-field="states"]');

            select.find('option').prop('selected', false);
            select.trigger('change');
        });

        root.on('click', '[data-role="show-add"]', function () {
            addForm.show();
            addToolbar.hide();
        });

        // See the admin panel's note: validation is bound once, and valid() is
        // only called while the form is visible.
        addForm.validation();

        root.on('click', '[data-role="cancel"]', function (event) {
            event.preventDefault();
            addForm.hide();
            addToolbar.show();
        });

        root.on('click', '[data-role="save"]', function () {
            // Magento's standard validation, not a server round-trip. The
            // required-entry classes on the form mirror CertificateFormReader,
            // so anything caught here is something the server would reject
            // anyway — reported against the offending field instead of as one
            // message for the whole form.
            if (!addForm.valid()) {
                return;
            }

            say($t('Saving your certificate…'));
            busy(true);

            $.post(config.endpoints.add, {
                form_key: $.mage.cookies.get('form_key'),
                certificate: collectForm()
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not save your certificate'));

                    return;
                }

                addForm.hide();
                load();

                // The form runs well below the fold; without this the saved
                // certificate lands off-screen and the click looks inert.
                if (root[0] && root[0].scrollIntoView) {
                    root[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }).fail(function () {
                failed(
                    $t('We could not save your certificate just now.'),
                    $t('Could not save your certificate')
                );
            }).always(function () {
                busy(false);
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

            busy(true);

            $.post(config.endpoints['delete'], {
                form_key: $.mage.cookies.get('form_key'),
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not remove your certificate'));

                    return;
                }

                load();
            }).fail(function () {
                failed(
                    $t('We could not remove that certificate just now.'),
                    $t('Could not remove your certificate')
                );
            }).always(function () {
                busy(false);
            });
        });

        load();
    };
});
