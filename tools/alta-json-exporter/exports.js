import {toImportDocument} from './core.js';
import {completeAssets} from './photos.js';

export const MAX_IMPORT_BYTES = 8 * 1024 * 1024;
export const MAX_IMPORT_PRODUCTS = 100;
const bytes = value => new TextEncoder().encode(value).byteLength;

export function importDocument(row, quantity, assets, includePhotos) {
    const result = toImportDocument(row.raw, {item: row.item, quantity});
    if (includePhotos) {
        if (!completeAssets(row.raw, assets)) throw new Error('photo_assets_missing');
        result.iapiAssets = structuredClone(assets);
    }
    return result;
}

// Keep only item references in the plan; photo payloads are loaded one product at a time.
export async function planImportParts(rows, loadDocument, {maxBytes = MAX_IMPORT_BYTES, maxProducts = MAX_IMPORT_PRODUCTS} = {}) {
    const parts = [];
    let part = {items: [], first: 1, last: 0, bytes: 2};
    for (const row of rows) {
        const document = await loadDocument(row);
        const size = bytes(JSON.stringify(document));
        if (size + 2 > maxBytes) throw new Error('import_product_too_large');
        if (part.items.length && (part.items.length >= maxProducts || part.bytes + 1 + size > maxBytes)) {
            parts.push(part);
            part = {items: [], first: part.last + 1, last: part.last, bytes: 2};
        }
        part.bytes += size + (part.items.length ? 1 : 0);
        part.items.push(row.item);
        part.last++;
    }
    if (part.items.length) parts.push(part);
    return parts;
}

export async function serializeImportPart(part, loadDocument, {maxBytes = MAX_IMPORT_BYTES, maxProducts = MAX_IMPORT_PRODUCTS} = {}) {
    if (!part || !Array.isArray(part.items) || !part.items.length || part.items.length > maxProducts) throw new Error('invalid_import_part');
    const documents = [];
    let length = 2;
    for (const item of part.items) {
        const json = JSON.stringify(await loadDocument({item}));
        length += bytes(json) + (documents.length ? 1 : 0);
        if (length > maxBytes) throw new Error('import_product_too_large');
        documents.push(json);
    }
    return `[${documents.join(',')}]`;
}
