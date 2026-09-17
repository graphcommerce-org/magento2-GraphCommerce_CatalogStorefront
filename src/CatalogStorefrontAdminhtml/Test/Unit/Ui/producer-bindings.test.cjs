'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const directory = path.resolve(__dirname, '../../../view/adminhtml/web/js/form');
function component(file) {
    let definition;
    vm.runInNewContext(fs.readFileSync(path.join(directory, file), 'utf8'), {define: (deps, factory) => { definition = factory({extend: value => value}, value => value); }});
    return definition;
}
function observable(initial) { let value = initial; return function (next) { if (arguments.length) value = next; return value; }; }
function picker(options) {
    return Object.assign({}, component('resource-picker.js'), {value: observable(''), options: observable([]), error: observable(false), filterInputValue() {}, setCaption() {}}, options);
}
const sources = [
    {value: '3', label: 'External', type: 'generic', enabled: true, locale: 'en_US'},
    {value: '4', label: 'Other', type: 'generic', enabled: true, locale: 'en_US'},
    {value: '5', label: 'Unbound', type: 'generic', enabled: true},
    {value: '9', label: 'Magento', type: 'platform_store_view', enabled: true, locale: 'en_US'}
];
test('Source picker offers enabled generic Sources only and keeps a selection outside them', () => {
    const field = picker({resourceFilter: 'sources', sourceRoster: [...sources, {value: '6', label: 'Retired', type: 'generic', enabled: false}],
        value: observable('9')});
    field.filterRoster();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["3","4","5"]');
    assert.equal(field.value(), '9'); assert.match(field.error(), /does not match/);
    field.value('4'); field.filterRoster();
    assert.equal(field.error(), false);
});
test('View Layer picker keeps Source ownership, locale and automatic global scope distinct', () => {
    const base = {type: 'generic', enabled: true, sourceId: '3', locale: '', scope: 'view'};
    const field = picker({resourceFilter: 'layers', sourceRoster: sources, sourceSelection: '3', layerRoster: [
        {...base, value: '10', label: 'Owned'}, {...base, value: '11', sourceId: '4'},
        {...base, value: '13', scope: 'global'}, {...base, value: '14', locale: 'de_DE'}, {...base, value: '15', sourceId: ''}
    ]});
    field.filterRoster(); assert.equal(JSON.stringify(field.options().map(row => row.value)), '["10"]');
    field.sourceSelection = '9'; field.filterRoster(); assert.equal(JSON.stringify(field.options().map(row => row.value)), '["15"]');
});
test('Canonical Layer offers Override only and never silently rewrites an existing Merge', () => {
    const field = Object.assign({}, component('layer-operation.js'), {value: observable('merge'), options: observable([]), error: observable(false), sourceBinding: '3',
        setOptions(options) { this.options(options); }});
    field.updateOperations();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["override"]');
    assert.equal(field.value(), 'merge'); assert.match(field.error(), /Override only/);
    field.sourceBinding = ''; field.updateOperations();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["override","merge"]'); assert.equal(field.error(), false);
});

test('View Book picker excludes native website parents and other websites without hiding generic Books', () => {
    const sourceRoster = [...sources, {value: '10', type: 'platform_store_view', nativeWebsiteId: '1'}];
    const bookRoster = [
        {value: '1', label: 'Website', enabled: true, type: 'platform_website', nativeWebsiteId: '1'},
        {value: '2', label: 'Guest', enabled: true, type: 'platform_customer_group', nativeWebsiteId: '1'},
        {value: '3', label: 'Other guest', enabled: true, type: 'platform_customer_group', nativeWebsiteId: '2'},
        {value: '4', label: 'Generic', enabled: true, type: 'generic'},
        {value: '5', label: 'Second generic', enabled: true, type: 'generic'},
        {value: '6', label: 'Retired', enabled: false, type: 'generic'}
    ];
    const field = picker({resourceFilter: 'books', sourceRoster, sourceSelection: '10', bookRoster, value: observable(['1', '2'])});
    field.filterRoster(); assert.equal(JSON.stringify(field.options().map(row => row.value)), '["2"]');
    assert.match(field.error(), /does not match/); assert.equal(JSON.stringify(field.value()), '["1","2"]');
    field.sourceSelection = '3'; field.value(['4', '5']); field.filterRoster();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["4","5"]'); assert.equal(field.error(), false);
});
