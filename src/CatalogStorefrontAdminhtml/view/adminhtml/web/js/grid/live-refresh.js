/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define(['uiRegistry', 'mage/translate'], function (registry, $t) {
    'use strict';

    var interval = 5000,
        entries = [],
        timer = null,
        inFlight = false,
        refreshRequested = false;

    function message(text) {
        entries.forEach(function (entry) {
            entry.listing.set('liveMessage', $t(text));
        });
    }

    function interacting() {
        var focused = document.activeElement;

        return !!document.querySelector('.catalog-overview .action-menu._active, .modal-popup._show, .modal-slide._show') ||
            !!(focused && focused.closest('.catalog-overview') && focused.matches('input, select, textarea, [contenteditable="true"]'));
    }

    function schedule(delay) {
        clearTimeout(timer);
        timer = null;
        if (entries.length && !document.hidden && !inFlight) {
            timer = setTimeout(refresh, delay === undefined ? interval : delay);
        }
    }

    function refresh() {
        var ready, remaining, results = [], failed = false;

        timer = null;
        if (inFlight) {
            refreshRequested = true;
            return;
        }
        if (document.hidden || !entries.length) {
            return;
        }
        if (interacting()) {
            schedule();
            return;
        }
        ready = entries.filter(function (entry) {
            return entry.provider && !entry.provider.firstLoad && !entry.loading;
        });
        if (!ready.length) {
            schedule();
            return;
        }
        inFlight = true;
        remaining = ready.length;

        function settled() {
            remaining--;
            if (remaining) { return; }
            inFlight = false;
            // A menu may have opened while the requests were in flight.
            // Keep its rows intact; the next refresh will pick up newer data.
            if (!document.hidden && !interacting()) {
                results.forEach(function (result) {
                    if (entries.indexOf(result.entry) !== -1 && !result.entry.loading) {
                        result.entry.provider.set('lastError', false);
                        result.entry.provider.setData(result.data);
                    }
                });
                message(failed ? 'Live updates unavailable. Retrying automatically.' : 'Live updates every 5 seconds');
            }
            schedule(refreshRequested ? 0 : interval);
            refreshRequested = false;
        }

        ready.forEach(function (entry) {
            try {
                // Use Magento's provider storage so row preparation and Admin ACL
                // remain identical to the initial listing request. All six reads
                // start together, without flashing the initial-loading overlay.
                entry.request = entry.provider.storage().getData(entry.provider.params, {refresh: true});
                entry.request.done(function (data) {
                    if (data && Array.isArray(data.items) && !data.error && !data.errorMessage) {
                        results.push({entry: entry, data: data});
                    } else {
                        failed = true;
                    }
                }).fail(function () {
                    failed = true;
                }).always(function () {
                    entry.request = null;
                    settled();
                });
            } catch (error) {
                failed = true;
                settled();
            }
        });
    }

    function visibilityChanged() {
        clearTimeout(timer);
        timer = null;
        if (document.hidden) {
            message('Live updates paused while this tab is hidden');
        } else {
            requestRefresh();
        }
    }

    function requestRefresh() {
        if (inFlight) {
            refreshRequested = true;
        } else {
            schedule(0);
        }
    }

    return {
        register: function (listing) {
            var entry = {listing: listing, provider: null, loading: false, request: null},
                namespace = 'catalog-live-refresh-' + listing.name;

            entries.push(entry);
            if (entries.length === 1) {
                document.addEventListener('visibilitychange', visibilityChanged);
            }
            registry.get(listing.provider, function (provider) {
                if (entries.indexOf(entry) === -1) { return; }
                entry.provider = provider;
                provider.on('reload', function () { entry.loading = true; }, namespace);
                provider.on('reloaded', function () { entry.loading = false; }, namespace);
                schedule();
            });
            return function () {
                var index = entries.indexOf(entry);

                if (index === -1) { return; }
                entries.splice(index, 1);
                if (entry.provider) { entry.provider.off(namespace); }
                if (!entries.length) {
                    clearTimeout(timer);
                    timer = null;
                    refreshRequested = false;
                    document.removeEventListener('visibilitychange', visibilityChanged);
                }
                if (entry.request && typeof entry.request.abort === 'function') { entry.request.abort(); }
            };
        },
        refresh: requestRefresh
    };
});
