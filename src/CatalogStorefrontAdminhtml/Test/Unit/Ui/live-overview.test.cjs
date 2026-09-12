'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const directory = path.resolve(__dirname, '../../../view/adminhtml/web/js');

function deferred() {
    const done = [], fail = [], always = [];
    return {
        done(callback) { done.push(callback); return this; },
        fail(callback) { fail.push(callback); return this; },
        always(callback) { always.push(callback); return this; },
        resolve(data) { done.forEach(callback => callback(data)); always.forEach(callback => callback()); },
        reject(data = {}) { fail.forEach(callback => callback(data)); always.forEach(callback => callback()); },
        abort() { this.aborted = true; this.reject({statusText: 'abort'}); }
    };
}
function load(file, dependencies, globals = {}) {
    let module;
    vm.runInNewContext(fs.readFileSync(path.join(directory, file), 'utf8'), {
        define: (names, factory) => { module = factory(...names.map(name => dependencies[name])); }, ...globals
    });
    return module;
}
function overview() {
    let serial = 0;
    const timers = new Map(), listeners = new Map(), providers = {}, requests = [];
    const document = {
        hidden: false, activeElement: null, interacting: false,
        querySelector() { return this.interacting ? {} : null; },
        addEventListener(event, callback) { listeners.set(event, callback); },
        removeEventListener(event, callback) { assert.equal(listeners.get(event), callback); listeners.delete(event); }
    };
    const coordinator = load('grid/live-refresh.js', {
        uiRegistry: {get(name, callback) { callback(providers[name]); }}, 'mage/translate': value => value
    }, {document, setTimeout: (callback, delay) => { timers.set(++serial, {callback, delay}); return serial; },
        clearTimeout: id => timers.delete(id)});
    function add(name) {
        const values = [], events = new Map(), listing = {name, provider: name, set(key, value) { this[key] = value; }};
        const provider = providers[name] = {
            firstLoad: false, params: {namespace: name},
            on(event, callback, namespace) { events.set(event, {callback, namespace}); },
            off(namespace) { for (const [event, entry] of events) if (entry.namespace === namespace) events.delete(event); },
            set(key, value) { this[key] = value; },
            setData(data) { values.push(data); },
            storage() { return {getData(params, options) {
                assert.equal(params.namespace, name); assert.equal(options.refresh, true);
                const request = deferred(); requests.push({name, request}); return request;
            }}; }
        };
        const remove = coordinator.register(listing);
        return {provider, listing, values, events, remove};
    }
    function tick() {
        const entries = [...timers]; timers.clear();
        entries.forEach(([, timer]) => timer.callback());
    }
    function visibility(hidden) { document.hidden = hidden; listeners.get('visibilitychange')(); }
    return {coordinator, add, tick, visibility, timers, listeners, requests, document};
}
const data = count => ({items: [{code: 'default', count}], totalRecords: 1});

test('six provider reads start together, apply as a batch, and cannot overlap', () => {
    const state = overview(); const grids = ['views', 'sources', 'books', 'stocks', 'layers', 'policies'].map(state.add);
    assert.equal(state.timers.size, 1);
    assert.equal([...state.timers.values()][0].delay, 5000);
    state.tick(); assert.equal(state.requests.length, 6); assert.equal(state.timers.size, 0);
    state.coordinator.refresh(); state.tick(); assert.equal(state.requests.length, 6);
    state.requests.slice(0, 5).forEach(({request}) => request.resolve(data(1)));
    assert.equal(grids[0].values.length, 0); assert.equal(state.timers.size, 0);
    state.requests[5].request.resolve(data(1));
    assert.ok(grids.every(grid => grid.values.length === 1));
    assert.equal([...state.timers.values()][0].delay, 0);
    state.tick(); assert.equal(state.requests.length, 12);
});

test('hidden tabs stop polling and do not apply in-flight responses; visible resumes immediately', () => {
    const state = overview(); const source = state.add('sources');
    state.tick(); state.visibility(true);
    state.requests[0].request.resolve(data(1));
    assert.equal(source.values.length, 0); assert.equal(state.timers.size, 0);
    assert.match(source.listing.liveMessage, /paused/);
    state.visibility(false); assert.equal([...state.timers.values()][0].delay, 0);
    state.tick(); state.requests[1].request.resolve(data(2));
    assert.equal(source.values[0].items[0].count, 2);
});

test('open actions and editing pause both request and response replacement', () => {
    const state = overview(); const source = state.add('sources');
    state.document.interacting = true; state.tick(); assert.equal(state.requests.length, 0);
    state.document.interacting = false; state.tick(); assert.equal(state.requests.length, 1);
    state.document.interacting = true; state.requests[0].request.resolve(data(1)); assert.equal(source.values.length, 0);
    state.document.interacting = false;
    state.document.activeElement = {closest: () => ({}), matches: () => true};
    state.tick(); assert.equal(state.requests.length, 1);
    state.document.activeElement = null; state.tick(); state.requests[1].request.resolve(data(2));
    assert.equal(source.values[0].items[0].count, 2);
});

test('a normal provider reload is not overlapped, and disposed grids never receive responses', () => {
    const state = overview(); const source = state.add('sources');
    source.events.get('reload').callback(); state.tick(); assert.equal(state.requests.length, 0);
    source.events.get('reloaded').callback(); state.tick(); assert.equal(state.requests.length, 1);
    const request = state.requests[0].request;
    source.remove(); assert.equal(request.aborted, true);
    assert.equal(source.values.length, 0); assert.equal(state.timers.size, 0); assert.equal(state.listeners.size, 0);
    assert.equal(source.events.size, 0); source.remove();
});

test('failed polling preserves old rows, reports stale data, and retries without modal storms', () => {
    const state = overview(); const source = state.add('sources');
    state.tick(); state.requests[0].request.resolve(data(1));
    state.tick(); state.requests[1].request.reject();
    assert.equal(source.values.length, 1); assert.match(source.listing.liveMessage, /unavailable/);
    state.tick(); state.requests[2].request.resolve(data(3));
    assert.equal(source.values[1].items[0].count, 3); assert.equal(source.listing.liveMessage, 'Live updates every 5 seconds');
});

test('unknown states do not invent progress and known states have explicit reindex labels', () => {
    const column = load('grid/columns/source.js', {
        'Magento_Ui/js/grid/columns/column': {extend: definition => definition}, 'mage/translate': value => value
    });
    assert.equal(column.getReindex({}), null);
    assert.equal(column.getReindex({reindex: {status: 'inherited'}}), null);
    assert.equal(column.getReindex({reindex: {status: 'constructor'}}), null);
    assert.equal(column.getReindexLabel({reindex: {status: 'running'}}), 'Indexing in background');
    assert.equal(column.getReindexLabel({reindex: {status: 'complete'}}), 'Reindex complete');
});

test('reindex posts once with form key and refreshes only after success', () => {
    const requests = [], alerts = []; let refreshes = 0;
    function $(html) {
        return {text(value) { this.value = value; return this; }, html() {
            return this.value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
        }};
    }
    $.ajax = config => { const request = deferred(); requests.push({config, request}); return request; };
    const reindex = load('reindex.js', {jquery: $, 'Magento_Ui/js/modal/alert': config => alerts.push(config),
        'mage/translate': value => value, 'GraphCommerce_CatalogStorefrontAdminhtml/js/grid/live-refresh': {refresh() { refreshes++; }}
    }, {window: {FORM_KEY: 'test-form-key'}});
    const action = {href: '/admin/reindex/source_id/1'};
    reindex(action); reindex(action); assert.equal(requests.length, 1);
    assert.equal(requests[0].config.type, 'POST'); assert.equal(requests[0].config.data.form_key, 'test-form-key');
    assert.equal(refreshes, 0); requests[0].request.resolve({success: true}); assert.equal(refreshes, 1);
    reindex(action); assert.equal(requests.length, 2);
    requests[1].request.resolve({success: false, message: '<script>bad</script>'});
    assert.equal(refreshes, 1); assert.equal(alerts[0].content, '&lt;script&gt;bad&lt;/script&gt;');
});

test('Source progress is isolated from the shared name cell used by ordinary grid columns', () => {
    const admin = path.resolve(directory, '../..');
    const template = fs.readFileSync(path.join(admin, 'web/template/grid/cells/source.html'), 'utf8');
    assert.doesNotMatch(template, /getReindex/);
    const sourceXml = fs.readFileSync(path.join(admin, 'ui_component/catalog_storefront_sources_listing.xml'), 'utf8');
    assert.match(sourceXml, /js\/grid\/columns\/source/);
    assert.match(sourceXml, /grid\/cells\/source-progress<\/bodyTmpl>/);
    for (const kind of ['stocks', 'layers', 'policies']) {
        const xml = fs.readFileSync(path.join(admin, `ui_component/catalog_storefront_${kind}_listing.xml`), 'utf8');
        assert.doesNotMatch(xml, /grid\/cells\/source-progress/);
    }
});
