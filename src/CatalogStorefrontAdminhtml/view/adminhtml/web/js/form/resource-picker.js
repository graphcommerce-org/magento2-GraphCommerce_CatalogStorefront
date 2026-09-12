define(['Magento_Ui/js/form/element/ui-select', 'mage/translate'], function (Select, $t) {
    'use strict';
    return Select.extend({
        defaults: {
            disableLabel: true,
            resourceFilter: '', sourceRoster: [], layerRoster: [],
            listens: {namespaceSelection: 'filterRoster', sourceSelection: 'filterRoster'}
        },
        initialize: function () {
            this._super();
            this.filterRoster();
            return this;
        },
        /** Presentation filtering only; the private service validates every saved binding. */
        filterRoster: function () {
            if (!this.resourceFilter || typeof this.value !== 'function' || typeof this.options !== 'function') return;
            var selected = this.value(), rows, namespace = String(this.namespaceSelection || ''), source;
            if (this.resourceFilter === 'sources') {
                rows = this.sourceRoster.filter(function (row) {
                    return row.type === 'generic' && row.enabled && row.identityNamespace && (!namespace || row.identityNamespace === namespace);
                }).map(function (row) { return {value: row.value, label: row.label + ' — ' + row.identityNamespace}; });
            } else {
                source = this.sourceRoster.find(function (row) { return row.value === String(this.sourceSelection || ''); }, this);
                rows = this.layerRoster.filter(function (row) {
                    if (!source || !row.enabled || row.type !== 'generic' || row.scope === 'global' || (row.locale && row.locale !== source.locale)) return false;
                    return source.type === 'generic' ? Boolean(source.identityNamespace) && row.sourceId === source.value && row.identityNamespace === source.identityNamespace : !row.sourceId;
                });
            }
            this.setOptions(rows);
            this.value(selected);
            this.error(selected && !rows.some(function (row) { return row.value === String(selected); }) ? $t('This selection does not match the current Source or product namespace.') : false);
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
