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
    {value: '3', label: 'External', type: 'generic', enabled: true, identityNamespace: 'products', locale: 'en_US'},
    {value: '4', label: 'Other', type: 'generic', enabled: true, identityNamespace: 'other', locale: 'en_US'},
    {value: '5', label: 'Unbound', type: 'generic', enabled: true, identityNamespace: ''},
    {value: '9', label: 'Magento', type: 'platform_store_view', enabled: true, locale: 'en_US'}
];
test('Source picker respects generic type and explicit namespace without changing selection', () => {
    const field = picker({resourceFilter: 'sources', sourceRoster: sources, namespaceSelection: 'products', value: observable('4')});
    field.filterRoster();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["3"]');
    assert.equal(field.value(), '4'); assert.match(field.error(), /does not match/);
    field.namespaceSelection = ''; field.filterRoster();
    assert.equal(JSON.stringify(field.options().map(row => row.value)), '["3","4"]');
    assert.equal(field.error(), false);
});
test('View Layer picker keeps Source ownership, namespace, locale and automatic global scope distinct', () => {
    const base = {type: 'generic', enabled: true, sourceId: '3', identityNamespace: 'products', locale: '', scope: 'view'};
    const field = picker({resourceFilter: 'layers', sourceRoster: sources, sourceSelection: '3', layerRoster: [
        {...base, value: '10', label: 'Owned'}, {...base, value: '11', sourceId: '4'}, {...base, value: '12', identityNamespace: 'other'},
        {...base, value: '13', scope: 'global'}, {...base, value: '14', locale: 'de_DE'}, {...base, value: '15', sourceId: '', identityNamespace: ''}
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
