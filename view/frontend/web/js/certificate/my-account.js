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
 * Every customer sees which certificate is in use. Customers the store has
 * nominated (the listing's canManage) also get attach / stop using, refresh
 * and the add form; the endpoints refuse everyone else whatever this renders.
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
            attached = '',
            canManage = false,
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

        /**
         * Whether this certificate is the one in use, and — for customers who
         * may change it — the control to do so.
         *
         * @param {Object} certificate
         * @param {Boolean} isAttached
         * @return {String}
         */
        function inUseCell(certificate, isAttached) {
            if (isAttached) {
                return '<strong>' + escapeHtml($t('In use')) + '</strong>' + (canManage
                    ? ' <a href="#" data-attach="">' + escapeHtml($t('Stop using')) + '</a>'
                    : '');
            }

            if (!canManage) {
                return '&mdash;';
            }

            return '<a href="#" data-attach="' + escapeHtml(certificate.certificateId) + '">' +
                escapeHtml($t('Use this certificate')) + '</a>';
        }

        function render(certificates) {
            rows.empty();

            if (!certificates.length) {
                table.hide();

                return;
            }

            certificates.forEach(function (certificate) {
                var isAttached = attached !== '' && attached === certificate.certificateId;

                rows.append(
                    '<tr>' +
                    '<td class="col">' + escapeHtml((certificate.states || []).join(', ')) + '</td>' +
                    '<td class="col">' + escapeHtml(certificate.purchaserName || '—') + '</td>' +
                    '<td class="col">' + escapeHtml(certificate.reason || '—') + '</td>' +
                    '<td class="col">' + inUseCell(certificate, isAttached) + '</td>' +
                    '<td class="col"><a href="#" data-delete="' + escapeHtml(certificate.certificateId) + '"' +
                    (isAttached ? ' data-in-use="1"' : '') + '>' +
                    escapeHtml($t('Remove')) + '</a></td>' +
                    '</tr>'
                );
            });

            table.show();
        }

        function load() {
            return $.get(config.endpoints.list).done(function (response) {
                if (!response.success) {
                    table.hide();
                    // Not an empty list — see the module docblock.
                    say(response.message || $t('We could not load your certificates just now.'), 'error');

                    return;
                }

                attached = response.attached || '';
                canManage = !!response.canManage;
                render(response.certificates || []);

                if (!response.certificates || !response.certificates.length) {
                    say(canManage
                        ? $t('You have no exemption certificates. Use Add Certificate to file one.')
                        : $t('You have no exemption certificates. Contact us if you believe you should be tax exempt.'));
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

        // See the admin panel's note: validation is bound once, and valid() is
        // only called while the form is visible.
        if (addForm.length) {
            addForm.validation();
        }

        root.on('click', '[data-role="show-add"]', function () {
            addForm.show();
        });

        root.on('click', '[data-role="cancel"]', function () {
            addForm.hide();
        });

        root.on('click', '[data-role="states-all"], [data-role="states-none"]', function (event) {
            var select = addForm.find('[data-field="states"]');

            event.preventDefault();
            select.find('option').prop('selected', $(this).data('role') === 'states-all');
            select.trigger('change');
        });

        root.on('click', '[data-role="refresh"]', function () {
            busy(true);

            $.post(config.endpoints.refresh, {
                form_key: $.mage.cookies.get('form_key')
            }).always(function () {
                load().always(function () {
                    busy(false);
                });
            });
        });

        root.on('click', '[data-role="save"]', function () {
            if (!addForm.valid()) {
                return;
            }

            busy(true);

            $.post(config.endpoints.add, {
                form_key: $.mage.cookies.get('form_key'),
                attestation: addForm.find('[data-role="attestation"]').is(':checked') ? '1' : '',
                certificate: collectForm()
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not add your certificate'));

                    return;
                }

                addForm.hide();
                addForm[0].reset();
                load().done(function () {
                    say(response.attached
                        ? $t('Your certificate has been added and is now in use.')
                        : $t('Your certificate has been added. The certificate marked "In use" still applies — choose "Use this certificate" to switch.'));
                });

                if (root[0] && root[0].scrollIntoView) {
                    root[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }).fail(function () {
                failed(
                    $t('We could not add your certificate just now.'),
                    $t('Could not add your certificate')
                );
            }).always(function () {
                busy(false);
            });
        });

        root.on('click', '[data-attach]', function (event) {
            var certificateId = $(this).attr('data-attach');

            event.preventDefault();

            if (certificateId === '' &&
                !window.confirm($t('Stop using this certificate? You will be charged tax on future orders until you choose one.'))) {
                return;
            }

            busy(true);

            $.post(config.endpoints.attach, {
                form_key: $.mage.cookies.get('form_key'),
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not update your certificate'));

                    return;
                }

                load();
            }).fail(function () {
                failed(
                    $t('We could not update your certificate just now.'),
                    $t('Could not update your certificate')
                );
            }).always(function () {
                busy(false);
            });
        });

        root.on('click', '[data-delete]', function (event) {
            var certificateId = $(this).data('delete'),
                question = $(this).data('in-use')
                    ? $t('This is the certificate in use. Remove it? You will be charged tax on future orders until another certificate is in use.')
                    : $t('Remove this certificate? It cannot be restored.');

            event.preventDefault();

            // Irreversible: TaxCloud cannot restore a deleted certificate, and
            // removing the one in use stops the exemption from the next order.
            if (!window.confirm(question)) {
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
