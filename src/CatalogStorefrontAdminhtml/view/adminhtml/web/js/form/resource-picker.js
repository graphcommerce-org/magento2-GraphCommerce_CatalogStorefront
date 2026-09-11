define(['Magento_Ui/js/form/element/ui-select'], function (Select) {
    'use strict';
    return Select.extend({
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
