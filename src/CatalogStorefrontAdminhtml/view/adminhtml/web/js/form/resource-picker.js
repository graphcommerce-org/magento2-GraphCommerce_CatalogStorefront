define(['Magento_Ui/js/form/element/ui-select', 'mage/translate'], function (Select, $t) {
    'use strict';
    return Select.extend({
        defaults: {
            disableLabel: true,
            resourceFilter: '', sourceRoster: [], layerRoster: [], bookRoster: [],
            listens: {sourceSelection: 'filterRoster'}
        },
        initialize: function () {
            this._super();
            this.filterRoster();
            return this;
        },
        /** Presentation filtering only; the private service validates every saved binding. */
        filterRoster: function () {
            if (!this.resourceFilter || typeof this.value !== 'function' || typeof this.options !== 'function') return;
            var selected = this.value(), rows, source;
            if (this.resourceFilter === 'sources') {
                rows = this.sourceRoster.filter(function (row) {
                    return row.type === 'generic' && row.enabled;
                }).map(function (row) { return {value: row.value, label: row.label}; });
            } else {
                source = this.sourceRoster.find(function (row) { return row.value === String(this.sourceSelection || ''); }, this);
                if (this.resourceFilter === 'books') {
                    rows = this.bookRoster.filter(function (row) {
                        if (!source || !row.enabled) return false;
                        if (source.type === 'platform_store_view') return row.type === 'platform_customer_group' && row.nativeWebsiteId === source.nativeWebsiteId;
                        return source.type === 'generic' && row.type === 'generic';
                    });
                } else {
                    rows = this.layerRoster.filter(function (row) {
                        if (!source || !row.enabled || row.scope === 'global' || (row.locale && row.locale !== source.locale)) return false;
                        if (row.type === 'platform_reviews') return true;
                        if (row.type !== 'generic') return false;
                        return source.type === 'generic' ? row.sourceId === source.value : !row.sourceId;
                    });
                }
            }
            this.setOptions(rows);
            this.value(selected);
            var selectedValues = (Array.isArray(selected) ? selected : [selected]).filter(function (value) { return value !== '' && value !== null && value !== undefined; });
            this.error(selectedValues.some(function (value) { return !rows.some(function (row) { return row.value === String(value); }); }) ? $t('This selection does not match the current Source.') : false);
        },
        /** Replace the entire source roster, including cached search options from the previous Source. */
        setOptions: function (options) {
            var rows = options.filter(function (row) { return row.value !== ''; }).map(function (row) {
                return {value: String(row.value), label: row.label, level: 0, path: ''};
            });
            this.cacheOptions = {plain: rows, tree: rows, lastOptions: []};
            this.filterInputValue('');
            this.options(rows);
            this.setCaption();
            return this;
        }
    });
});
