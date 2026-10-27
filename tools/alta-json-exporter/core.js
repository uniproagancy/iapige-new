export const MAX_CANDIDATES = 5;
export const STOCK_MAX_AGE_MS = 26 * 60 * 60 * 1000;
const ALTA_ORIGIN = 'https://alta.ge';
const STOCK_ERRORS = new Map([
    ['invalid_feed_link', 'invalid_feed_link'], ['invalid_stock_feed_link', 'invalid_feed_link'],
    ['supplier_unavailable', 'supplier_unavailable'], ['stock_snapshot_missing', 'stock_snapshot_missing'],
    ['stock_snapshot_unavailable', 'stock_snapshot_missing'], ['stock_snapshot_stale', 'stock_snapshot_stale'],
]);

function itemCode(value) {
    if (typeof value === 'number' && !Number.isSafeInteger(value)) throw new Error('invalid_item');
    if (typeof value !== 'string' && typeof value !== 'number') throw new Error('invalid_item');
    const item = String(value).trim();
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(item)) throw new Error('invalid_item');
    return item;
}

function quantity(value) {
    if (value === undefined || value === null || value === '') return null;
    if (typeof value === 'string' && !/^\d+$/.test(value.trim())) throw new Error('invalid_quantity');
    if (typeof value !== 'string' && typeof value !== 'number') throw new Error('invalid_quantity');
    const number = Number(value);
    if (!Number.isSafeInteger(number) || number < 0) throw new Error('invalid_quantity');
    return number;
}

function uniqueEntries(entries) {
    const unique = new Map();
    for (const entry of entries) {
        const previous = unique.get(entry.item);
        if (previous && previous.quantity !== null && entry.quantity !== null && previous.quantity !== entry.quantity) {
            throw new Error('conflicting_quantity');
        }
        if (!previous || previous.quantity === null) unique.set(entry.item, entry);
    }
    return [...unique.values()];
}

export function parseCodes(text) {
    if (typeof text !== 'string') throw new Error('invalid_codes');
    return uniqueEntries(text.split(/[\s,;]+/).filter(Boolean).map(value => ({item: itemCode(value), quantity: null})));
}

export function parseFeed(value) {
    if (typeof value === 'string') {
        try { value = JSON.parse(value); } catch { throw new Error('invalid_feed_json'); }
    }
    let rows;
    if (Array.isArray(value)) rows = value;
    else if (Array.isArray(value?.items)) rows = value.items;
    else if (value?.PriceList?.items?.item) {
        const supplied = value.PriceList.items.item;
        rows = Array.isArray(supplied) ? supplied : [supplied];
    } else throw new Error('invalid_feed');
    return uniqueEntries(rows.map(row => {
        if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('invalid_feed_row');
        return {item: itemCode(row.item), quantity: quantity(row.quantity ?? row.qty_text)};
    }));
}

export function validateFeedUrl(value) {
    if (typeof value !== 'string' || value.includes('#')) throw new Error('invalid_feed_link');
    let url;
    try { url = new URL(value.trim()); } catch { throw new Error('invalid_feed_link'); }
    const keys = [...url.searchParams.keys()];
    if (url.protocol !== 'https:' || url.hostname !== 'iapi.ge' || url.username || url.password
        || /^https:\/\/[^/?#]*@/i.test(value.trim()) || (url.port && url.port !== '443')
        || url.pathname !== '/feeds/alta-stock.json' || keys.length !== 2
        || url.searchParams.getAll('version').length !== 1 || url.searchParams.getAll('signature').length !== 1
        || keys.some(key => key !== 'version' && key !== 'signature')
        || !/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(url.searchParams.get('version') || '')
        || !/^[0-9a-f]{64}$/i.test(url.searchParams.get('signature') || '')) {
        throw new Error('invalid_feed_link');
    }
    return url.href;
}

export function assertStockFresh(snapshot, now = Date.now()) {
    if (snapshot?.source !== 'signed_feed') return snapshot;
    const collected = typeof snapshot.syncedAt === 'string' ? Date.parse(snapshot.syncedAt) : NaN;
    if (!Number.isFinite(now) || !Number.isFinite(collected) || now - collected > STOCK_MAX_AGE_MS
        || collected - now > 5 * 60 * 1000) throw new Error('stock_snapshot_stale');
    return snapshot;
}

export function parseStockSnapshot(value, now = Date.now()) {
    if (typeof value === 'string') {
        try { value = JSON.parse(value); } catch { throw new Error('invalid_feed'); }
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('invalid_feed');
    if (Object.hasOwn(value, 'error')) {
        throw new Error(STOCK_ERRORS.get(value.error) || 'invalid_feed');
    }
    if (value.schema !== 'iapi.supplier-stock.v1' || value.supplier !== 'alta' || value.source !== 'supplier_stocks'
        || !Array.isArray(value.items) || !Number.isSafeInteger(value.count) || value.count !== value.items.length
        || value.items.some(row => !row || typeof row !== 'object' || Array.isArray(row)
            || typeof row.item !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(row.item)
            || !Number.isSafeInteger(row.quantity) || row.quantity < 0)) throw new Error('invalid_feed');
    const fresh = assertStockFresh({source: 'signed_feed', syncedAt: value.synced_at}, now);
    let items;
    try { items = parseFeed(value); } catch { throw new Error('invalid_feed'); }
    if (items.length !== value.count) throw new Error('invalid_feed');
    return {items, syncedAt: new Date(fresh.syncedAt).toISOString(), source: 'signed_feed'};
}

function positiveId(value) {
    if (typeof value === 'string' && !/^\d+$/.test(value.trim())) return null;
    if (typeof value !== 'string' && typeof value !== 'number') return null;
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

export function candidateIds(suggestions, expectedItem) {
    const products = Array.isArray(suggestions) ? suggestions : suggestions?.products;
    if (!Array.isArray(products)) throw new Error('invalid_suggestions');
    const expected = expectedItem === undefined ? null : itemCode(expectedItem);
    const exact = [], unknown = [];
    for (const product of products) {
        if (!product || typeof product !== 'object' || Array.isArray(product)) throw new Error('invalid_suggestions');
        const id = positiveId(product.id);
        if (id === null) continue;
        const barcode = product.barCode === undefined || product.barCode === null ? '' : String(product.barCode).trim();
        if (expected !== null && barcode !== '' && barcode !== expected) continue;
        (expected !== null && barcode === expected ? exact : unknown).push(id);
    }
    return [...new Set([...exact, ...unknown])].slice(0, MAX_CANDIDATES);
}

export function exactDetails(raw, expectedItem) {
    const expected = itemCode(expectedItem);
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)
        || !raw.product || typeof raw.product !== 'object' || Array.isArray(raw.product)
        || positiveId(raw.product.id) === null) throw new Error('invalid_details');
    const barcode = raw.product.barCode;
    if (typeof barcode !== 'string' && typeof barcode !== 'number') return null;
    return String(barcode).trim() === expected ? raw : null;
}

export function toImportDocument(raw, entry) {
    if (!entry || !exactDetails(raw, entry.item)) throw new Error('barcode_mismatch');
    if (entry.quantity === null || entry.quantity === undefined) throw new Error('stock_unknown');
    const stock = quantity(entry.quantity);
    if (stock === null) throw new Error('stock_unknown');
    const price = raw.product.price;
    if ((typeof price !== 'number' && (typeof price !== 'string' || !/^\d+(?:\.\d+)?$/.test(price.trim())))
        || !Number.isFinite(Number(price)) || Number(price) <= 0) throw new Error('invalid_retail_price');
    if (typeof raw.product.name !== 'string' || !raw.product.name.trim()) throw new Error('missing_product_name');
    const result = structuredClone(raw);
    result.product.storageQuantity = stock;
    result.availabilityInStores = [];
    return result;
}

export function suggestionsUrl(item) {
    return `${ALTA_ORIGIN}/api/proxy/v1/Products/search/suggestions?query=${encodeURIComponent(itemCode(item))}`;
}

export function detailsUrl(id) {
    const valid = positiveId(id);
    if (valid === null) throw new Error('invalid_product_id');
    return `${ALTA_ORIGIN}/api/proxy/v1/Products/details?productId=${valid}`;
}
