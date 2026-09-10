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
            bodyTmpl: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/cells/more',
            sortable: false,
            controlVisibility: false,
            draggable: false,
            menuLabel: 'More actions',
            unavailableTitle: 'More actions',
            unavailableText: 'This management workflow is not available yet.'
        },

        /**
         * Show a native Magento modal without rendering row data as HTML.
         */
        showUnavailable: function () {
            uiAlert({
                title: $t(this.unavailableTitle),
                content: $t(this.unavailableText)
            });
        }
    });
});
