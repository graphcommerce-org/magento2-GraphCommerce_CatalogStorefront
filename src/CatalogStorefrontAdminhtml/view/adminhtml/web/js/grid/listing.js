/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define([
    'Magento_Ui/js/grid/listing',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'GraphCommerce_CatalogStorefrontAdminhtml/js/grid/live-refresh',
    'GraphCommerce_CatalogStorefrontAdminhtml/js/reindex'
], function (Listing, uiAlert, $t, liveRefresh, reindex) {
    'use strict';

    return Listing.extend({
        defaults: {
            template: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/listing',
            heading: '',
            liveMessage: $t('Live updates every 5 seconds'),
            actionLabel: '',
            infoText: '',
            dataset: '',
            actionUrl: '',
            unavailableText: 'This management workflow is not available yet.'
        },

        initialize: function () {
            this._super();
            this.stopLiveRefresh = liveRefresh.register(this);
            return this;
        },

        initObservable: function () {
            this._super().track('liveMessage');
            return this;
        },

        destroy: function () {
            if (this.stopLiveRefresh) { this.stopLiveRefresh(); }
            return this._super();
        },

        requestReindex: function (actionIndex, recordId, action) {
            reindex(action);
        },

        /**
         * Return the current provider row count.
         *
         * @returns {Number}
         */
        getRecordCount: function () {
            return this.rows ? this.rows.length : 0;
        },

        /**
         * Show the factual description configured for this listing.
         */
        showInfo: function () {
            uiAlert({
                title: $t(this.heading),
                content: $t(this.infoText)
            });
        },

        openCreate: function () {
            if (this.actionUrl) { window.location.assign(this.actionUrl); }
        }
    });
});
