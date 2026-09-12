/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define([
    'Magento_Ui/js/grid/columns/column',
    'mage/translate'
], function (Column, $t) {
    'use strict';

    var labels = {
        queued: 'Reindex queued',
        running: 'Indexing in background',
        publishing: 'Publishing catalog',
        complete: 'Reindex complete',
        failed: 'Reindex failed'
    };

    return Column.extend({
        getReindex: function (row) {
            var operation = row.reindex;

            return operation && Object.prototype.hasOwnProperty.call(labels, operation.status) ? operation : null;
        },

        getReindexLabel: function (row) {
            var operation = this.getReindex(row);

            return operation ? $t(labels[operation.status]) : '';
        }
    });
});
