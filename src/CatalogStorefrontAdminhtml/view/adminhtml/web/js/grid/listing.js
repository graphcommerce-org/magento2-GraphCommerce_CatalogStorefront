/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define([
    'Magento_Ui/js/grid/listing',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function (Listing, uiAlert, $t) {
    'use strict';

    return Listing.extend({
        defaults: {
            template: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/listing',
            heading: '',
            actionLabel: '',
            infoText: '',
            dataset: '',
            actionUrl: '',
            unavailableText: 'This management workflow is not available yet.'
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
