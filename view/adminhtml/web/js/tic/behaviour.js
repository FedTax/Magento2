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
 * The TIC autocomplete's behaviour, independent of where it is mounted.
 *
 * Two adapters mix this in: the form element used by the product and category
 * attributes, and the standalone element used by the Default TIC and Shipping
 * TIC configuration fields. They differ only in how they obtain a value —
 * Magento's form components import it from a form data provider, which
 * system.xml fields do not have — so the interaction itself lives here once and
 * behaves identically in all four places.
 *
 * It decorates the field it already was: the value stays whatever the admin
 * typed, and search only ever fills it in. A code the lookup does not know is
 * reported and kept, never corrected, and never blocks a save.
 */
define([
    'jquery',
    'underscore',
    'mage/translate'
], function ($, _, $t) {
    'use strict';

    return {
        defaults: {
            searchUrl: '',
            storeId: null,
            minChars: 2,
            debounceMs: 250,
            fallbackHint: '',
            suggestions: [],
            highlighted: -1,
            open: false,
            state: 'idle',
            resolvedLabel: '',
            unavailableReason: '',
            loading: false,
            // Tracked, not observed: every assignment below is a plain
            // property write (this.suggestions = […]). Declaring these in
            // initObservable().observe() as well would replace the observable
            // with a raw value on first assignment, and foreach would then
            // diff a non-observable.
            tracks: {
                suggestions: true,
                highlighted: true,
                open: true,
                state: true,
                resolvedLabel: true,
                unavailableReason: true,
                loading: true
            }
        },

        /**
         * Shared start-up: debounce the search and explain any current value.
         *
         * @returns {Object} chainable
         */
        initTic: function () {
            // Each request carries a sequence number; only the newest answer is
            // allowed to land. Cheaper and more reliable than aborting, and it
            // makes a slow early response unable to overwrite a newer one.
            this.requestSeq = 0;
            this.search = _.debounce(this.search.bind(this), this.debounceMs);

            if (this.value()) {
                this.resolveCurrent();
            } else {
                this.state = 'idle';
            }

            return this;
        },

        /**
         * Typing means the admin is looking for something; a value they picked
         * or that was loaded means we should explain it.
         *
         * @param {String} value
         */
        onValueChange: function (value) {
            if (!this.userTyping) {
                this.resolveCurrent();
            }
        },

        /**
         * Keystroke handler bound in the template.
         *
         * @param {Object} ctx
         * @param {Event} event
         * @returns {Boolean} true so the keystroke still reaches the input
         */
        onKeyInput: function (ctx, event) {
            this.userTyping = true;
            this.state = 'searching';
            this.search(String(this.value() || ''));

            return true;
        },

        /**
         * Look up candidates for a query.
         *
         * @param {String} query
         */
        search: function (query) {
            query = (query || '').trim();

            if (query.length < this.minChars) {
                // Deleting back below the minimum must also abandon whatever is
                // already in flight, or that answer lands afterwards and
                // repopulates a list the admin has just cleared.
                this.abandonInFlight();
                this.suggestions = [];
                this.open = false;
                this.state = 'idle';

                return;
            }

            this.request({query: query, mode: 'search'}, function (data) {
                if (!data.available) {
                    this.suggestions = [];
                    this.open = false;
                    this.state = 'unavailable';
                    this.unavailableReason = data.reason || '';

                    return;
                }

                this.suggestions = data.suggestions || [];
                this.highlighted = this.suggestions.length ? 0 : -1;
                this.open = this.suggestions.length > 0;
                this.state = this.suggestions.length ? 'searching' : 'nomatch';
            });
        },

        /**
         * Explain the code currently in the field.
         */
        resolveCurrent: function () {
            var code = String(this.value() || '').trim();

            this.open = false;
            this.suggestions = [];

            if (!code) {
                this.state = 'idle';
                this.resolvedLabel = '';

                return;
            }

            this.request({query: code, mode: 'resolve'}, function (data) {
                if (!data.available) {
                    this.state = 'unavailable';
                    this.unavailableReason = data.reason || '';

                    return;
                }

                if (data.suggestions && data.suggestions.length) {
                    this.resolvedLabel = data.suggestions[0].label;
                    this.state = 'resolved';
                } else {
                    // Kept, not corrected: an unknown code is still saved as
                    // entered, so this is information, not an error.
                    this.resolvedLabel = '';
                    this.state = 'notfound';
                }
            });
        },

        /**
         * POST to the lookup endpoint, ignoring stale answers.
         *
         * Owns the loading flag because it is the one place every lookup goes
         * through, search and resolve alike. Only the newest request may clear
         * it: an older answer landing late must not stop the spinner while a
         * newer request is still out.
         *
         * @param {Object} params
         * @param {Function} done
         */
        request: function (params, done) {
            var seq = ++this.requestSeq,
                self = this;

            if (!this.searchUrl) {
                this.state = 'unavailable';
                this.unavailableReason = 'not_configured';
                this.loading = false;

                return;
            }

            this.loading = true;

            $.ajax({
                url: self.searchUrl,
                type: 'POST',
                dataType: 'json',
                data: _.extend({store: self.storeId, form_key: window.FORM_KEY}, params),
                showLoader: false
            }).done(function (data) {
                if (seq !== self.requestSeq) {
                    return;
                }
                self.loading = false;
                done.call(self, data || {});
            }).fail(function () {
                if (seq !== self.requestSeq) {
                    return;
                }
                self.loading = false;
                self.state = 'unavailable';
                self.unavailableReason = 'transport';
            });
        },

        /**
         * Stop caring about any request already out: the sequence check in
         * request() then discards its answer, and the spinner stops.
         */
        abandonInFlight: function () {
            this.requestSeq++;
            this.loading = false;
        },

        /**
         * Take a suggestion into the field.
         *
         * @param {Object} suggestion
         */
        select: function (suggestion) {
            // The admin has chosen; a search still in flight is now irrelevant
            // and must not reopen the list behind them.
            this.abandonInFlight();
            this.userTyping = false;
            this.value(suggestion.code);
            this.resolvedLabel = suggestion.label;
            this.state = 'resolved';
            this.open = false;
            this.suggestions = [];
        },

        /**
         * Arrow / enter / escape while the list is open.
         *
         * @param {Object} ctx
         * @param {Event} event
         * @returns {Boolean} false only when the key was consumed
         */
        onKeyDown: function (ctx, event) {
            var list = this.suggestions || [];

            if (event.keyCode === 27) {
                this.open = false;

                return false;
            }

            if (!this.open || !list.length) {
                return true;
            }

            if (event.keyCode === 40) {
                this.highlighted = Math.min(this.highlighted + 1, list.length - 1);

                return false;
            }

            if (event.keyCode === 38) {
                this.highlighted = Math.max(this.highlighted - 1, 0);

                return false;
            }

            if (event.keyCode === 13 && this.highlighted >= 0) {
                this.select(list[this.highlighted]);

                return false;
            }

            return true;
        },

        /**
         * Closing the list must not discard what was typed.
         */
        onBlur: function () {
            var self = this;

            // Deferred so a click on a suggestion registers before the list goes.
            _.delay(function () {
                self.open = false;
                if (self.userTyping) {
                    self.userTyping = false;
                    self.resolveCurrent();
                }
            }, 150);
        },

        /**
         * @param {Number} index
         * @returns {Boolean}
         */
        isHighlighted: function (index) {
            return this.highlighted === index;
        },

        /**
         * @param {Object} suggestion
         * @returns {String} e.g. "94%", empty when the backend does not rank
         */
        relevance: function (suggestion) {
            if (typeof suggestion.score !== 'number') {
                return '';
            }

            return Math.round(suggestion.score * 100) + '%';
        },

        /**
         * Wording for why lookup is unavailable. The unconfigured case gets its
         * own sentence: on the configuration screen the TIC fields sit beside
         * the credential fields, so "not configured yet" is the ordinary state
         * during first-time setup, not a fault.
         *
         * @returns {String}
         */
        unavailableText: function () {
            if (this.unavailableReason === 'not_configured') {
                return $t('Save your TaxCloud credentials to enable TIC search. You can still enter a code.');
            }

            if (this.unavailableReason === 'auth_failed') {
                return $t('TaxCloud did not accept the saved credentials, so TIC search is unavailable.');
            }

            return $t('TIC search is unavailable right now. You can still enter a code.');
        },

        /**
         * @returns {String}
         */
        notFoundText: function () {
            return $t('Not in your TaxCloud TIC list. It will be saved exactly as entered.');
        }
    };
});
