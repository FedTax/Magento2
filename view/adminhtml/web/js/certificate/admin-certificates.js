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
            tableRegion = root.find('[data-role="table-region"]'),
            spinner = root.find('[data-role="spinner"]'),
            attached = '',
            pending = 0;

        /**
         * @param {String} message
         * @param {String} [tone] 'error' when this is a failure rather than a fact
         */
        function say(message, tone) {
            status.text(message).css('color', tone === 'error' ? '#e22626' : '');
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
         * The controller catches TaxCloud errors itself and answers with JSON,
         * so arriving here means the request never reached working PHP. These
         * cases need different responses from the administrator and used to
         * render as one indistinguishable sentence — an expired session, by far
         * the most common, read as "TaxCloud is down".
         *
         * A 200 that still lands in fail() is jQuery failing to parse the body:
         * almost always a redirect to the login page.
         *
         * @param {Object} xhr
         * @return {String}
         */
        function readFailure(xhr) {
            var status = xhr && xhr.status;

            if (!status) {
                return $t('The request to Magento did not complete. Check your connection and try again.');
            }

            if (status === 200) {
                return $t(
                    'Magento answered with something other than certificate data, which usually means ' +
                    'your admin session has expired. Reload the page and sign in again.'
                );
            }

            if (status === 401 || status === 403) {
                return $t('Your admin session has expired. Reload the page and sign in again.');
            }

            if (status >= 500) {
                return $t('Magento failed while reading certificates. The reason will be in the store logs.') +
                    ' (HTTP ' + status + ')';
            }

            return $t('Could not read certificates.') + ' (HTTP ' + status + ')';
        }

        /**
         * Loader spanning just the certificate table.
         *
         * Deliberately the admin grid's own mask rather than mage/loader: the
         * latter renders a "Please wait" spinner that appears nowhere else on
         * this page, so it read as something foreign rather than as the table
         * reloading.
         *
         * The minimum height keeps the mask visible when the table is hidden
         * because the customer has no certificates.
         *
         * @param {Boolean} isBusy
         */
        function tableBusy(isBusy) {
            tableRegion.css('min-height', isBusy ? '120px' : '');
            spinner.toggle(isBusy);
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

        /**
         * Whether this certificate is the one the customer's orders are filed
         * against, and the control to change that.
         *
         * Worth a column of its own: holding a certificate and having it apply
         * are different states, and the difference decides whether the customer
         * is taxed. A panel that showed only the list left an administrator
         * unable to see which — or any way to say.
         *
         * @param {Object} certificate
         * @param {Boolean} isAttached
         * @return {String}
         */
        function inUseCell(certificate, isAttached) {
            if (isAttached) {
                return '<strong>' + escapeHtml($t('In use')) + '</strong> ' +
                    '<button type="button" class="action-secondary" data-attach="">' +
                    escapeHtml($t('Stop using')) + '</button>';
            }

            // A disabled certificate can never exempt anything, so offering to
            // attach it would promise something the resolver refuses to keep.
            if (certificate.disabled) {
                return '<em>' + escapeHtml($t('disabled')) + '</em>';
            }

            return '<button type="button" class="action-secondary" data-attach="' +
                escapeHtml(certificate.certificateId) + '">' + escapeHtml($t('Use for this customer')) +
                '</button>';
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
                        : '',
                    isAttached = attached !== '' && attached === certificate.certificateId;

                rows.append(
                    '<tr>' +
                    '<td><code>' + escapeHtml(certificate.certificateId) + '</code>' + disabled + '</td>' +
                    '<td>' + statesCell(certificate) + '</td>' +
                    '<td>' + detail(certificate, 'purchaserName') + '</td>' +
                    '<td>' + detail(certificate, 'reason') + '</td>' +
                    '<td>' + inUseCell(certificate, isAttached) + '</td>' +
                    '<td><button type="button" class="action-secondary" data-delete="' +
                    escapeHtml(certificate.certificateId) + '">' + escapeHtml($t('Delete')) + '</button></td>' +
                    '</tr>'
                );
            });

            table.show();
        }

        /**
         * Say which identity the certificates below were looked up under, and
         * why that might matter.
         *
         * A bare number explains nothing. What an administrator actually needs
         * is the answer to "why is this list empty when I know they have a
         * certificate" — which is almost always that the certificate was
         * created in the TaxCloud portal under a customer id somebody typed by
         * hand, and it does not match what Magento asks with.
         *
         * So the default case states the consequence in one line and stays out
         * of the way; the overridden case is called out, because someone
         * deliberately pointed this customer elsewhere and that is worth
         * seeing at a glance.
         */
        function describeIdentity(response) {
            var line = root.find('[data-role="identity-line"]'),
                id = response.identity || '—';

            line.empty();

            if (response.identityIsDefault) {
                line.append(
                    $('<span>').text(
                        $t('Looked up in TaxCloud under customer ID') + ' '
                    ),
                    $('<strong>').text(id),
                    $('<span>').text(
                        ' — ' + $t(
                            'this customer\'s Magento ID. If their certificate was created in the ' +
                            'TaxCloud portal under a different ID, it will not appear here until the ' +
                            'TaxCloud Customer ID field in Account Information is set to match.'
                        )
                    )
                );

                return;
            }

            line.append(
                $('<strong>').text($t('Looked up under a custom TaxCloud customer ID:') + ' '),
                $('<strong>').text(id),
                $('<span>').text(
                    ' — ' + $t(
                        'set on this customer in Account Information, instead of their Magento ID.'
                    )
                )
            );
        }

        function load() {
            say($t('Reading certificates from TaxCloud…'));
            tableBusy(true);

            return $.get(config.endpoints.list).done(function (response) {
                if (!response.success) {
                    // Never fall through to an empty table: that would report a
                    // failed read as "this customer is not exempt".
                    table.hide();
                    say(response.message || $t('Could not read certificates from TaxCloud.'), 'error');

                    return;
                }

                attached = response.attached || '';
                describeIdentity(response);

                render(response.certificates || []);

                if (!response.certificates || !response.certificates.length) {
                    say($t(
                        'TaxCloud holds no certificates under this ID. If this customer has one, ' +
                        'check the TaxCloud Customer ID above matches what was entered in the TaxCloud portal.'
                    ));
                } else {
                    say('');
                }
            }).fail(function (xhr) {
                table.hide();
                say(readFailure(xhr), 'error');
            }).always(function () {
                tableBusy(false);
            });
        }

        function collectForm() {
            var payload = {};

            addForm.find('[data-field]').each(function () {
                // A multiselect answers with an array; the reader accepts both,
                // but sending the array keeps the intent obvious on the wire.
                payload[$(this).data('field')] = $(this).val() || '';
            });

            return payload;
        }

        root.on('click', '[data-role="refresh"]', function () {
            say($t('Discarding cached certificates…'));
            busy(true);
            tableBusy(true);
            $.post(config.endpoints.refresh, {
                form_key: window.FORM_KEY
            }).always(function () {
                // load() runs its own scoped loader; drop this one only once
                // the re-read has finished, so the table is never briefly
                // uncovered between the two requests.
                load().always(function () {
                    tableBusy(false);
                    busy(false);
                });
            });
        });


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
        });

        // Initialised once the form exists. Fields are hidden until the form is
        // opened, and jQuery validation skips hidden inputs — harmless here
        // because valid() is only ever called while the form is on screen.
        addForm.validation();

        root.on('click', '[data-role="cancel"]', function () {
            addForm.hide();
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

            say($t('Creating certificate…'));
            busy(true);

            $.post(config.endpoints.add, {
                form_key: window.FORM_KEY,
                certificate: collectForm()
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not create certificate'));

                    return;
                }

                addForm.hide();
                load();

                // The Save button sits at the foot of a long form, so the new
                // certificate appears off-screen above. Return to the panel
                // heading so the result of the click is what you are looking at.
                if (root[0] && root[0].scrollIntoView) {
                    root[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }).fail(function () {
                failed(
                    $t('Could not reach Magento to create the certificate.'),
                    $t('Could not create certificate')
                );
            }).always(function () {
                busy(false);
            });
        });

        root.on('click', '[data-attach]', function () {
            var certificateId = $(this).attr('data-attach');

            say(certificateId === ''
                ? $t('Clearing the attached certificate…')
                : $t('Attaching certificate…'));
            busy(true);
            tableBusy(true);

            $.post(config.endpoints.attach, {
                form_key: window.FORM_KEY,
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not attach certificate'));

                    return;
                }

                load();
            }).fail(function () {
                failed(
                    $t('Could not reach Magento to attach the certificate.'),
                    $t('Could not attach certificate')
                );
            }).always(function () {
                tableBusy(false);
                busy(false);
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
            busy(true);

            $.post(config.endpoints['delete'], {
                form_key: window.FORM_KEY,
                certificate_id: certificateId
            }).done(function (response) {
                if (!response.success) {
                    failed(response.message, $t('Could not delete certificate'));

                    return;
                }

                load();
            }).fail(function () {
                failed(
                    $t('Could not reach Magento to delete the certificate.'),
                    $t('Could not delete certificate')
                );
            }).always(function () {
                busy(false);
            });
        });

        load();
    };
});
