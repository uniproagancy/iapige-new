import test from 'node:test';
import assert from 'node:assert/strict';
import {importDocument, planImportParts, serializeImportPart, MAX_IMPORT_BYTES, MAX_IMPORT_PRODUCTS} from '../exports.js';

const url = 'https://imgstore.alta.ge/images/a.png';
const row = {item: '001234', raw: {product: {id: 1, barCode: '001234', name: 'ქართული ტექსტი', price: 100,
    storageQuantity: 99, imageUrl: url}, availabilityInStores: [{inStock: true}], original: {preserve: true}}};
const assets = [{url, base64: 'c3ludGhldGlj'}];

test('only the import copy gains iapiAssets and B2B zero; original details and separate assets stay unchanged', () => {
    const before = structuredClone(row);
    const imported = importDocument(row, 0, assets, true);
    assert.deepEqual(imported.iapiAssets, assets);
    assert.equal(imported.product.storageQuantity, 0);
    assert.deepEqual(imported.availabilityInStores, []);
    imported.iapiAssets[0].base64 = 'changed';
    assert.deepEqual(row, before);
    assert.equal(assets[0].base64, 'c3ludGhldGlj');
    assert.equal(importDocument(row, 1, null, false).iapiAssets, undefined);
    assert.throws(() => importDocument(row, 0, [], true), /photo_assets_missing/);
});

test('every product is assigned exactly once and 201 products are split at the uploader limit', async () => {
    const rows = Array.from({length: 201}, (_, i) => ({item: String(i)}));
    const parts = await planImportParts(rows, async ({item}) => ({item}));
    assert.equal(MAX_IMPORT_PRODUCTS, 100);
    assert.equal(MAX_IMPORT_BYTES, 8 * 1024 * 1024);
    assert.deepEqual(parts.map(part => part.items.length), [100, 100, 1]);
    assert.deepEqual(parts.map(part => [part.first, part.last]), [[1, 100], [101, 200], [201, 201]]);
    assert.deepEqual(parts.flatMap(part => part.items), rows.map(row => row.item));
});

test('UTF-8 serialized bytes including array punctuation bound each part and selected downloads contain all rows', async () => {
    const rows = Array.from({length: 5}, (_, i) => ({item: String(i)}));
    const documents = new Map(rows.map(({item}) => [item, {item, text: 'ქართული'.repeat(3)}]));
    const load = async ({item}) => documents.get(item);
    const maxBytes = 180;
    const parts = await planImportParts(rows, load, {maxBytes});
    const exported = [];
    for (const part of parts) {
        const text = await serializeImportPart(part, load, {maxBytes});
        assert.ok(new TextEncoder().encode(text).length <= maxBytes);
        assert.equal(new TextEncoder().encode(text).length, part.bytes);
        exported.push(...JSON.parse(text));
    }
    assert.deepEqual(exported, [...documents.values()]);
});

test('a single oversized product and a changed oversized selected part are rejected without silent omission', async () => {
    await assert.rejects(planImportParts([{item: 'a'}], async () => ({text: 'a'.repeat(100)}), {maxBytes: 50}), /import_product_too_large/);
    await assert.rejects(serializeImportPart({items: ['a']}, async () => ({text: 'a'.repeat(100)}), {maxBytes: 50}), /import_product_too_large/);
    await assert.rejects(serializeImportPart({items: []}, async () => ({})), /invalid_import_part/);
});
