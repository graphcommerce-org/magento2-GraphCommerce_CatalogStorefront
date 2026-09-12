/**
 * Copyright 2026 GraphCommerce
 * SPDX-License-Identifier: MIT
 */
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'GraphCommerce_CatalogStorefrontAdminhtml/js/grid/live-refresh'
], function ($, uiAlert, $t, liveRefresh) {
    'use strict';

    var pending = {};

    function showError(message) {
        uiAlert({
            title: $t('Catalog reindex'),
            content: $('<div>').text(message || $t('Unable to queue the catalog reindex. Please try again.')).html()
        });
    }

    return function (action) {
        var url = action.href;

        if (!url || pending[url]) { return; }
        pending[url] = true;
        $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: {form_key: window.FORM_KEY}
        }).done(function (result) {
            if (!result || result.success !== true) {
                showError(result && result.message);
                return;
            }
            liveRefresh.refresh();
        }).fail(function (xhr) {
            showError(xhr.responseJSON && xhr.responseJSON.message);
        }).always(function () {
            delete pending[url];
        });
    };
});
