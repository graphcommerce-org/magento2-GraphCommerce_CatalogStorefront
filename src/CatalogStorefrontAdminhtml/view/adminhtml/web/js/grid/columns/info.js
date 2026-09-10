/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define([
    'Magento_Ui/js/grid/columns/column',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function (Column, uiAlert, $t) {
    'use strict';

    return Column.extend({
        defaults: {
            headerTmpl: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/columns/info',
            infoText: ''
        },

        /**
         * Show the factual availability note configured for this column.
         */
        showInfo: function () {
            uiAlert({
                title: $t(this.label),
                content: $t(this.infoText)
            });
        }
    });
});
