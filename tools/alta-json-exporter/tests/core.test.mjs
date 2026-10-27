import test from 'node:test';
import assert from 'node:assert/strict';
import {MAX_CANDIDATES, STOCK_MAX_AGE_MS, parseCodes, parseFeed, candidateIds, exactDetails, toImportDocument,
    suggestionsUrl, detailsUrl, validateFeedUrl, parseStockSnapshot, assertStockFresh} from '../core.js';

function rawDetails(changes = {}) {
    return {product: {id: 741, barCode: '001234', name: 'Original API name', price: 129,
        previousPrice: 199, storageQuantity: 99, description: '<p>Original description.</p>',
        categoryName: 'Original category', images: ['https://imgstore.alta.ge/original.webp'],
        nested: {untouched: [1, {value: 'raw'}]}, ...changes},
    availabilityInStores: [{inStock: true}], extraEnvelope: {trace: 'preserve me'}};
}

test('pasted codes preserve leading zeroes and deduplicate without inventing quantities', () => {
    assert.deepEqual(parseCodes('001234, 172951\n001234;176426\t176505'), [
        {item: '001234', quantity: null}, {item: '172951', quantity: null},
        {item: '176426', quantity: null}, {item: '176505', quantity: null},
    ]);
    assert.deepEqual(parseCodes('  '), []);
    assert.throws(() => parseCodes('../001234'), /invalid_item/);
});

test('admin feed includes out-of-stock rows and retains explicit zero quantities', () => {
    assert.deepEqual(parseFeed({items: [{item: '001234', quantity: 0, name: 'Ignored label'},
        {item: '172951', quantity: 3}]}), [{item: '001234', quantity: 0}, {item: '172951', quantity: 3}]);
    assert.deepEqual(parseFeed('[{"item":"001234","quantity":0}]'), [{item: '001234', quantity: 0}]);
});

test('normalized SOAP arrays and single raw SOAP rows accept only numeric quantities', () => {
    assert.deepEqual(parseFeed({PriceList: {items: {item: {item: '001234', qty_text: ' 0 '}}}}),
        [{item: '001234', quantity: 0}]);
    assert.deepEqual(parseFeed([{item: '001234'}]), [{item: '001234', quantity: null}]);
    for (const qty of ['>=5', 'in stock', -1, 1.5, true]) {
        assert.throws(() => parseFeed([{item: '001234', quantity: qty}]), /invalid_quantity/);
    }
});

test('duplicate feed rows merge known quantities but conflicting stock rejects the feed', () => {
    assert.deepEqual(parseFeed([{item: '001234'}, {item: '001234', quantity: 0}, {item: '001234', quantity: 0}]),
        [{item: '001234', quantity: 0}]);
    assert.throws(() => parseFeed([{item: '001234', quantity: 0}, {item: '001234', quantity: 3}]), /conflicting_quantity/);
    assert.throws(() => parseFeed('{bad-json'), /invalid_feed_json/);
    assert.throws(() => parseFeed({items: {unexpected: []}}), /invalid_feed/);
});

test('suggestion IDs prioritize exact barcodes, skip known mismatches and remain bounded', () => {
    const candidates = [{id: 4, barCode: 'wrong'}, {id: 5}, {id: 741, barCode: '001234'},
        {id: 741, barCode: '001234'}, ...Array.from({length: 10}, (_, i) => ({id: i + 800}))];
    assert.deepEqual(candidateIds({products: candidates}, '001234'), [741, 5, 800, 801, 802]);
    assert.equal(candidateIds({products: candidates}, '001234').length, MAX_CANDIDATES);
    assert.deepEqual(candidateIds({products: []}, '001234'), []);
    assert.throws(() => candidateIds({error: 'blocked'}), /invalid_suggestions/);
});

test('invalid or unsafe candidate IDs never enter normal navigation URLs', () => {
    assert.deepEqual(candidateIds({products: [{id: 0}, {id: -2}, {id: 1.5}, {id: 'https://other.invalid'},
        {id: '741'}, {id: 741}]}), [741]);
    assert.equal(suggestionsUrl('001234'), 'https://alta.ge/api/proxy/v1/Products/search/suggestions?query=001234');
    assert.equal(detailsUrl('741'), 'https://alta.ge/api/proxy/v1/Products/details?productId=741');
    assert.throws(() => suggestionsUrl('x&query=wrong'), /invalid_item/);
    assert.throws(() => detailsUrl('../1'), /invalid_product_id/);
});

test('details identity is established by the exact barcode, including leading zeroes', () => {
    const raw = rawDetails();
    assert.equal(exactDetails(raw, '001234'), raw);
    assert.equal(exactDetails(rawDetails({barCode: '1234'}), '001234'), null);
    assert.equal(exactDetails(rawDetails({barCode: 1234}), '001234'), null);
    assert.equal(exactDetails(rawDetails({barCode: 'other'}), '001234'), null);
    assert.throws(() => exactDetails({product: null}, '001234'), /invalid_details/);
    assert.throws(() => exactDetails(rawDetails({id: 0}), '001234'), /invalid_details/);
});

test('uploader document uses B2B zero stock and preserves full raw metadata without mutation', () => {
    const raw = rawDetails();
    const before = structuredClone(raw);
    const imported = toImportDocument(raw, {item: '001234', quantity: 0});
    assert.equal(imported.product.storageQuantity, 0);
    assert.deepEqual(imported.availabilityInStores, []);
    assert.equal(imported.product.price, 129);
    assert.equal(imported.product.previousPrice, 199);
    assert.equal(imported.product.description, before.product.description);
    assert.deepEqual(imported.product.nested, before.product.nested);
    assert.deepEqual(imported.extraEnvelope, before.extraEnvelope);
    assert.deepEqual(raw, before);
    imported.product.nested.untouched[1].value = 'changed export';
    assert.equal(raw.product.nested.untouched[1].value, 'raw');
});

test('unknown B2B quantities permit raw collection but reject uploader-ready export', () => {
    const raw = rawDetails();
    assert.equal(exactDetails(raw, '001234'), raw);
    assert.throws(() => toImportDocument(raw, {item: '001234', quantity: null}), /stock_unknown/);
    assert.equal(raw.product.storageQuantity, 99);
    assert.equal(raw.availabilityInStores.length, 1);
});

test('retail prices remain unrounded and untranslated; unusable prices cannot import', () => {
    const raw = rawDetails({price: 129.25, previousPrice: 199.75, description: 'English original text'});
    const imported = toImportDocument(raw, {item: '001234', quantity: 4});
    assert.equal(imported.product.price, 129.25);
    assert.equal(imported.product.previousPrice, 199.75);
    assert.equal(imported.product.description, 'English original text');
    for (const price of [0, null, '', 'not a price', Infinity]) {
        assert.throws(() => toImportDocument(rawDetails({price}), {item: '001234', quantity: 4}), /invalid_retail_price/);
    }
    assert.throws(() => toImportDocument(rawDetails({barCode: 'other'}), {item: '001234', quantity: 4}), /barcode_mismatch/);
});

const SNAPSHOT_NOW = Date.parse('2026-10-28T12:00:00Z');
const SIGNED_LINK = `https://iapi.ge/feeds/alta-stock.json?version=12345678-abcd-4abc-8def-123456789abc&signature=${'a'.repeat(64)}`;

function stockSnapshot(changes = {}) {
    return {schema: 'iapi.supplier-stock.v1', supplier: 'alta', source: 'supplier_stocks',
        synced_at: '2026-10-28T11:00:00Z', count: 2,
        items: [{item: '001234', quantity: 0}, {item: '172951', quantity: 3}], ...changes};
}

test('signed feed link allows only the exact HTTPS endpoint and two unique signed parameters', () => {
    assert.equal(validateFeedUrl(SIGNED_LINK), SIGNED_LINK);
    assert.equal(validateFeedUrl(SIGNED_LINK.replace('https://iapi.ge/', 'https://IAPI.GE:443/')), SIGNED_LINK);
    for (const link of [SIGNED_LINK.replace('https:', 'http:'), SIGNED_LINK.replace('iapi.ge/', 'iapi.ge.evil.invalid/'),
        SIGNED_LINK.replace('iapi.ge/', 'user:password@iapi.ge/'), SIGNED_LINK.replace('iapi.ge/', '@iapi.ge/'),
        SIGNED_LINK.replace('iapi.ge/', 'iapi.ge:444/'), SIGNED_LINK.replace('/feeds/alta-stock.json', '/admin/alta-browser/feed'),
        `${SIGNED_LINK}#fragment`, `${SIGNED_LINK}#`, `${SIGNED_LINK}&extra=1`, `${SIGNED_LINK}&version=duplicate`,
        `${SIGNED_LINK}&signature=${'b'.repeat(64)}`, SIGNED_LINK.replace(/version=[^&]+/, 'version=not-a-uuid'),
        SIGNED_LINK.replace(/signature=.*/, 'signature=short'), SIGNED_LINK.replace(/&signature=.*/, '')]) {
        assert.throws(() => validateFeedUrl(link), /invalid_feed_link/);
    }
});

test('fresh signed stock snapshot retains zero stock and canonicalizes its source timestamp', () => {
    const raw = stockSnapshot({synced_at: '2026-10-28T15:00:00+04:00'});
    const before = structuredClone(raw);
    assert.deepEqual(parseStockSnapshot(raw, SNAPSHOT_NOW), {
        items: [{item: '001234', quantity: 0}, {item: '172951', quantity: 3}],
        syncedAt: '2026-10-28T11:00:00.000Z', source: 'signed_feed',
    });
    assert.deepEqual(raw, before);
    assert.equal(parseStockSnapshot(JSON.stringify(raw), SNAPSHOT_NOW).items.length, 2);
});

test('signed snapshot freshness enforces the26-hour limit and five-minute future allowance', () => {
    const atLimit = {source: 'signed_feed', syncedAt: new Date(SNAPSHOT_NOW - STOCK_MAX_AGE_MS).toISOString()};
    assert.equal(assertStockFresh(atLimit, SNAPSHOT_NOW), atLimit);
    assert.throws(() => assertStockFresh(atLimit, SNAPSHOT_NOW + 1), /stock_snapshot_stale/);
    const futureLimit = {source: 'signed_feed', syncedAt: new Date(SNAPSHOT_NOW + 300_000).toISOString()};
    assert.equal(assertStockFresh(futureLimit, SNAPSHOT_NOW), futureLimit);
    assert.throws(() => assertStockFresh({...futureLimit, syncedAt: new Date(SNAPSHOT_NOW + 300_001).toISOString()}, SNAPSHOT_NOW), /stock_snapshot_stale/);
    for (const synced_at of ['invalid', null, undefined, new Date(SNAPSHOT_NOW - STOCK_MAX_AGE_MS - 1).toISOString()]) {
        assert.throws(() => parseStockSnapshot(stockSnapshot({synced_at}), SNAPSHOT_NOW), /stock_snapshot_stale/);
    }
    const file = {source: 'file', syncedAt: 'invalid'};
    assert.equal(assertStockFresh(file, SNAPSHOT_NOW), file);
    assert.equal(assertStockFresh(null, SNAPSHOT_NOW), null);
});

test('malformed signed snapshot contracts cannot supply stock or silently deduplicate signed rows', () => {
    for (const changes of [{schema: 'other'}, {supplier: 'other'}, {source: 'api'}, {count: '2'}, {count: 3},
        {count: 2, items: [{item: '001234', quantity: 0}, {item: '001234', quantity: 0}]},
        {count: 2, items: [{item: '001234', quantity: 0}, {item: '001234', quantity: 3}]},
        {count: 1, items: [{item: 1234, quantity: 0}]}, {count: 1, items: [{item: '001234', quantity: '0'}]},
        {count: 1, items: [{item: '001234', quantity: null}]}, {count: 1, items: [{item: '001234', quantity: -1}]}]) {
        assert.throws(() => parseStockSnapshot(stockSnapshot(changes), SNAPSHOT_NOW), /invalid_feed/);
    }
});

test('revoked or unavailable signed-feed responses throw their approved code and never accept bundled items', () => {
    for (const error of ['invalid_feed_link', 'supplier_unavailable', 'stock_snapshot_missing', 'stock_snapshot_stale']) {
        assert.throws(() => parseStockSnapshot({...stockSnapshot(), error}, SNAPSHOT_NOW), new RegExp(`^Error: ${error}$`));
    }
    assert.throws(() => parseStockSnapshot({...stockSnapshot(), error: 'private_internal_error'}, SNAPSHOT_NOW), /invalid_feed/);
});

test('actual backend error aliases normalize to the existing UI codes without accepting bundled stock', () => {
    for (const [error, expected] of [['invalid_stock_feed_link', 'invalid_feed_link'],
        ['stock_snapshot_unavailable', 'stock_snapshot_missing']]) {
        assert.throws(() => parseStockSnapshot({...stockSnapshot(), error}, SNAPSHOT_NOW), new RegExp(`^Error: ${expected}$`));
        assert.throws(() => parseStockSnapshot({error}, SNAPSHOT_NOW), new RegExp(`^Error: ${expected}$`));
    }
});
