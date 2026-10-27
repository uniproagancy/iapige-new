import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as core from '../core.js';

const source = fs.readFileSync(new URL('../panel.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
const clone = value => value === undefined ? undefined : structuredClone(value);
const FEED_LINK = `https://iapi.ge/feeds/alta-stock.json?version=11111111-1111-4111-8111-111111111111&signature=${'a'.repeat(64)}`;
const stockSnapshot = (items, syncedAt = new Date().toISOString()) => ({schema: 'iapi.supplier-stock.v1',
    supplier: 'alta', source: 'supplier_stocks', synced_at: syncedAt, count: items.length, items});
const details = item => ({product: {id: Number(item), barCode: item, name: `Synthetic ${item}`, price: 129,
    previousPrice: 199, storageQuantity: 77, description: 'Original API text', images: ['https://alta.ge/photo.jpg']},
availabilityInStores: [{inStock: true}], untouched: {flag: true}});

async function fixture(read, {grantIapi = true, permissionFailure = null, clickWhileInitializing = false,
    initialMemory = {}, initialRecords = []} = {}) {
    const memory = clone(initialMemory), records = new Map(clone(initialRecords)), downloads = [], blobs = new Map(), requests = [], permissions = [];
    const ids = ['load-feed', 'feed-url', 'feed-file', 'codes', 'limit', 'start', 'pause', 'resume', 'message', 'position',
        'collected', 'missing', 'progress', 'download-import', 'download-raw', 'download-status', 'feed-status'];
    const elements = Object.fromEntries(ids.map(id => [id, {value: '', files: [], handlers: {},
        addEventListener(type, fn) { this.handlers[type] = fn; }}]));
    elements.limit.value = '3';
    elements['feed-url'].value = FEED_LINK;
    let locked = false;
    let userGesture = false;
    const click = id => {
        userGesture = true;
        try { elements[id].handlers.click(); } finally { userGesture = false; }
    };
    const context = vm.createContext({...core, structuredClone, crypto, Blob, Map, Set, Promise, Number, Date,
        URL: {createObjectURL(blob) { const id = `blob:${blobs.size}`; blobs.set(id, blob); return id; }, revokeObjectURL() {}},
        setTimeout(fn, ms) { const timer = setTimeout(fn, ms); timer.unref(); return timer; },
        document: {getElementById: id => elements[id], createElement: () => ({click() { downloads.push({url: this.href, name: this.download}); }})},
        window: {addEventListener() {}},
        navigator: {locks: {request: async (name, options, fn) => {
            if (locked) return fn(null);
            locked = true;
            try { return await fn({name}); } finally { locked = false; }
        }}},
        chrome: {permissions: {request(options) {
            assert.equal(userGesture, true, 'Permission must be requested synchronously in the original click.');
            permissions.push(clone(options));
            if (permissionFailure === 'throw') throw new Error('Synthetic permission error');
            if (permissionFailure === 'reject') return Promise.reject(new Error('Synthetic permission error'));
            return Promise.resolve(grantIapi);
        }}, storage: {local: {
            get: async keys => Object.fromEntries((Array.isArray(keys) ? keys : [keys]).map(key => [key, clone(memory[key])])),
            set: async values => Object.assign(memory, clone(values)),
        }}},
        createBrowserReader: () => ({readJson: async url => { requests.push(url); return read(url); }}),
        saveResult: async (jobId, entry, raw, sequence) => records.set(`${jobId}:${entry.item}`, {jobId, item: entry.item,
            quantity: entry.quantity, raw: clone(raw), sequence}),
        getResult: async (jobId, item) => clone(records.get(`${jobId}:${item}`)),
        countResults: async jobId => [...records.values()].filter(row => row.jobId === jobId).length,
        listResults: async jobId => clone([...records.values()].filter(row => row.jobId === jobId).sort((a, b) => a.sequence - b.sequence)),
    });
    vm.runInContext(source, context);
    if (clickWhileInitializing) click('load-feed');
    await until(() => !elements.start.disabled);
    return {memory, records, requests, elements, downloads, blobs, permissions, click,
        async importFeed(items) {
            elements['feed-file'].files = [{text: async () => JSON.stringify({items})}];
            elements['feed-file'].handlers.change();
            await until(() => !elements.start.disabled && memory.altaJsonExporterFeed?.length === items.length);
        }};
}

async function until(predicate) {
    const deadline = Date.now() + 2000;
    while (!predicate()) {
        if (Date.now() >= deadline) throw new Error('Panel fixture did not settle');
        await new Promise(resolve => setTimeout(resolve, 5));
    }
}

test('panel preserves raw data, blocks unknown-stock import, and exports B2B zero after loading its feed', async () => {
    const raw = details('001234');
    const f = await fixture(async url => url.includes('/search/suggestions') ? {products: [{id: 1234}]} : clone(raw));
    f.elements.codes.value = '001234';
    f.click('start');
    await until(() => f.memory.altaJsonExporterJob?.status === 'complete' && !f.elements.start.disabled);
    assert.equal(f.memory.altaJsonExporterJob.collected, 1);
    assert.ok(!JSON.stringify(f.memory).includes('Original API text'), 'Raw bodies never enter chrome.storage.local.');
    f.click('download-import');
    await until(() => f.elements.message.textContent.includes('B2B მარაგი'));
    assert.equal(f.downloads.length, 0);
    await f.importFeed([{item: '001234', quantity: 0}]);
    f.click('download-import');
    await until(() => f.downloads.length === 1);
    const imported = JSON.parse(await f.blobs.get(f.downloads[0].url).text());
    assert.equal(imported[0].product.storageQuantity, 0);
    assert.deepEqual(imported[0].availabilityInStores, []);
    assert.deepEqual(imported[0].untouched, raw.untouched);
    f.click('download-raw');
    await until(() => f.downloads.length === 2);
    assert.deepEqual(JSON.parse(await f.blobs.get(f.downloads[1].url).text()), [raw]);
    assert.equal(f.records.values().next().value.raw.product.storageQuantity, 77);
    assert.equal(f.permissions.length, 0, 'Pasted codes and file input do not request IAPI permission.');
    assert.ok(f.requests.every(url => new URL(url).origin === 'https://alta.ge'));
});

test('Pause preserves the completed checkpoint, ignores a late response, and Resume reads only the pending product', async () => {
    let release, hold = true;
    const f = await fixture(async url => {
        const parsed = new URL(url);
        if (url.includes('/search/suggestions')) return {products: [{id: Number(parsed.searchParams.get('query'))}]};
        const item = parsed.searchParams.get('productId').padStart(6, '0');
        if (item === '001235' && hold) return new Promise(resolve => { release = () => resolve(details(item)); });
        return details(item);
    });
    await f.importFeed([{item: '001234', quantity: 2}, {item: '001235', quantity: 0}]);
    f.click('start');
    f.click('load-feed');
    assert.equal(f.permissions.length, 0, 'A running collector cannot request another host permission.');
    await until(() => typeof release === 'function');
    assert.equal(f.memory.altaJsonExporterJob.cursor, 1);
    assert.equal(f.records.size, 1);
    f.click('start');
    assert.equal(f.requests.filter(url => url.includes('query=001234')).length, 1, 'A second Start cannot overlap the current run.');
    f.click('pause');
    await until(() => f.memory.altaJsonExporterJob.status === 'paused');
    release();
    await until(() => !f.elements.start.disabled);
    assert.equal(f.records.size, 1, 'A response after Pause is not checkpointed.');
    assert.equal(f.memory.altaJsonExporterJob.cursor, 1);
    hold = false;
    f.click('resume');
    await until(() => f.memory.altaJsonExporterJob.status === 'complete' && !f.elements.start.disabled);
    assert.equal(f.records.size, 2);
    assert.equal(f.memory.altaJsonExporterJob.cursor, 2);
    assert.equal(f.requests.filter(url => url.includes('query=001234')).length, 1);
});

test('denied IAPI permission never navigates there and leaves codes and file input working', async () => {
    const f = await fixture(async url => url.includes('/search/suggestions') ? {products: [{id: 1234}]} : details('001234'), {grantIapi: false});
    f.click('load-feed');
    assert.equal(f.permissions.length, 1);
    await until(() => !f.elements.start.disabled && f.elements.message.textContent.includes('არ დაუშვით'));
    assert.equal(f.requests.length, 0);
    await f.importFeed([{item: '001234', quantity: 0}]);
    f.elements.codes.value = '001234';
    f.click('start');
    await until(() => f.memory.altaJsonExporterJob?.status === 'complete' && !f.elements.start.disabled);
    assert.ok(f.requests.every(url => new URL(url).origin === 'https://alta.ge'));
    assert.equal(f.permissions.length, 1);
});

test('one explicit granted permission action reads and persists the signed feed without starting collection', async () => {
    const endpoint = FEED_LINK;
    const snapshot = stockSnapshot([{item: '001234', quantity: 0}]);
    const f = await fixture(async url => {
        assert.equal(url, endpoint);
        return snapshot;
    });
    f.click('load-feed');
    assert.deepEqual(f.permissions, [{origins: ['https://iapi.ge/*']}]);
    await until(() => f.memory.altaJsonExporterFeed?.length === 1 && !f.elements.start.disabled);
    assert.deepEqual(f.requests, [endpoint]);
    assert.equal(f.memory.altaJsonExporterJob, undefined);
    assert.equal(f.memory.altaJsonExporterFeed[0].quantity, 0);
    assert.equal(f.memory.altaJsonExporterFeedUrl, FEED_LINK);
    assert.equal(f.memory.altaJsonExporterFeedMeta.syncedAt, snapshot.synced_at);
    assert.equal(f.memory.altaJsonExporterFeedMeta.source, 'signed_feed');
    const reopened = await fixture(async () => { throw new Error('No automatic navigation'); }, {initialMemory: f.memory});
    assert.equal(reopened.elements['feed-url'].value, FEED_LINK);
    assert.equal(reopened.requests.length, 0);
});

test('permission failures release the panel controls and never navigate to IAPI', async () => {
    for (const permissionFailure of ['throw', 'reject']) {
        const f = await fixture(async () => { throw new Error('No navigation expected'); }, {permissionFailure});
        f.click('load-feed');
        await until(() => !f.elements.start.disabled && f.elements.message.textContent.includes('ვერ შესრულდა'));
        assert.equal(f.requests.length, 0);
        assert.equal(f.permissions.length, 1);
        assert.equal(f.elements['feed-file'].disabled, false);
    }
});

test('initializing panel never opens a host permission prompt', async () => {
    const f = await fixture(async () => { throw new Error('No navigation expected'); }, {clickWhileInitializing: true});
    assert.equal(f.permissions.length, 0);
    assert.equal(f.requests.length, 0);
});

test('invalid feed link is rejected before permission or navigation', async () => {
    const f = await fixture(async () => { throw new Error('No navigation'); });
    f.elements['feed-url'].value = 'https://foreign.invalid/feeds/alta-stock.json';
    f.click('load-feed');
    assert.equal(f.permissions.length, 0);
    assert.equal(f.requests.length, 0);
    assert.equal(f.memory.altaJsonExporterFeedUrl, undefined);
    assert.ok(f.elements.message.textContent.includes('ბმული'));
});

test('signed feed error responses never replace an already loaded list', async () => {
    for (const error of ['invalid_feed_link', 'supplier_unavailable', 'stock_snapshot_missing', 'stock_snapshot_stale']) {
        const f = await fixture(async () => ({error}));
        await f.importFeed([{item: '001234', quantity: 2}]);
        f.click('load-feed');
        await until(() => f.requests.length === 1 && !f.elements.start.disabled);
        assert.deepEqual(f.memory.altaJsonExporterFeed, [{item: '001234', quantity: 2}]);
        assert.equal(f.memory.altaJsonExporterFeedMeta, null);
        assert.equal(f.elements.message.className, 'error');
    }
});

test('stale saved signed feed blocks a new collection before Alta navigation', async () => {
    const f = await fixture(async () => { throw new Error('No navigation'); }, {initialMemory: {
        altaJsonExporterFeed: [{item: '001234', quantity: 2}],
        altaJsonExporterFeedMeta: {source: 'signed_feed', syncedAt: new Date(Date.now() - core.STOCK_MAX_AGE_MS - 1000).toISOString()},
    }});
    f.click('start');
    await until(() => !f.elements.start.disabled && f.elements.message.textContent.includes('მოძველებულია'));
    assert.equal(f.requests.length, 0);
    assert.equal(f.memory.altaJsonExporterJob, undefined);
});

test('stale job stock blocks Resume and import download while preserving the raw download', async () => {
    const metadata = {source: 'signed_feed', syncedAt: new Date(Date.now() - core.STOCK_MAX_AGE_MS - 1000).toISOString()};
    const job = {id: 'stale-job', version: 1, status: 'paused', cursor: 1, collected: 1, missing: 0,
        items: [{item: '001234', quantity: 2}, {item: '001235', quantity: 0}], stockSnapshot: metadata};
    const raw = details('001234');
    const f = await fixture(async () => { throw new Error('No navigation'); }, {initialMemory: {
        altaJsonExporterJob: job, altaJsonExporterFeed: job.items, altaJsonExporterFeedMeta: metadata,
    }, initialRecords: [['stale-job:001234', {jobId: 'stale-job', item: '001234', quantity: 2, sequence: 0, raw}]]});
    f.click('resume');
    await until(() => !f.elements.start.disabled && f.elements.message.textContent.includes('მოძველებულია'));
    assert.equal(f.requests.length, 0);
    f.click('download-import');
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(f.downloads.length, 0);
    f.click('download-raw');
    await until(() => f.downloads.length === 1);
    assert.deepEqual(JSON.parse(await f.blobs.get(f.downloads[0].url).text()), [raw]);
});
