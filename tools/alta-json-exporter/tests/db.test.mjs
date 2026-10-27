import test from 'node:test';
import assert from 'node:assert/strict';

test('IndexedDB v2 adds a separate photo store without replacing v1 raw responses', async () => {
    const old = {key: 'old:001234', jobId: 'old', item: '001234', raw: {product: {barCode: '001234'}, original: true}};
    const stores = new Map([['results', new Map([[old.key, structuredClone(old)]])]]);
    const created = [];
    const database = {
        objectStoreNames: {contains: name => stores.has(name)},
        createObjectStore(name) {
            assert.equal(stores.has(name), false);
            created.push(name);
            stores.set(name, new Map());
            return {createIndex() {}};
        },
        transaction(name) {
            const tx = {objectStore() {
                const request = action => {
                    const result = {};
                    queueMicrotask(() => { result.result = action(); result.onsuccess?.(); queueMicrotask(() => tx.oncomplete?.()); });
                    return result;
                };
                return {
                    put: row => request(() => { stores.get(name).set(row.key, structuredClone(row)); return row.key; }),
                    get: key => request(() => structuredClone(stores.get(name).get(key))),
                };
            }};
            return tx;
        },
    };
    const previous = globalThis.indexedDB;
    globalThis.indexedDB = {open(name, version) {
        assert.equal(name, 'iapi-alta-json-exporter');
        assert.equal(version, 2);
        const request = {result: database};
        queueMicrotask(() => { request.onupgradeneeded(); request.onsuccess(); });
        return request;
    }};
    try {
        const db = await import(`../db.js?fixture=${Date.now()}`);
        assert.deepEqual(await db.getResult('old', '001234'), old);
        await db.saveAssets('old', '001234', [{url: 'https://imgstore.alta.ge/images/a.png', base64: 'c3ludGhldGlj'}]);
        assert.deepEqual(created, ['assets']);
        assert.deepEqual(await db.getResult('old', '001234'), old);
        assert.equal((await db.getAssets('old', '001234'))[0].base64, 'c3ludGhldGlj');
        assert.equal(stores.get('results').size, 1);
    } finally {
        if (previous === undefined) delete globalThis.indexedDB;
        else globalThis.indexedDB = previous;
    }
});
