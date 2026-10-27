let opening;

function database() {
    if (!opening) opening = new Promise((resolve, reject) => {
        const request = indexedDB.open('iapi-alta-json-exporter', 2);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains('results')) {
                const store = request.result.createObjectStore('results', {keyPath: 'key'});
                store.createIndex('jobId', 'jobId');
            }
            if (!request.result.objectStoreNames.contains('assets')) request.result.createObjectStore('assets', {keyPath: 'key'});
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(new Error('storage_failed'));
        request.onblocked = () => reject(new Error('storage_blocked'));
    });
    return opening;
}

async function transaction(mode, action, name = 'results') {
    const db = await database();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(name, mode);
        let result;
        const request = action(tx.objectStore(name));
        request.onsuccess = () => { result = request.result; };
        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(new Error('storage_failed'));
        tx.onabort = () => reject(new Error('storage_failed'));
    });
}

export function saveAssets(jobId, item, assets) {
    return transaction('readwrite', store => store.put({key: `${jobId}:${item}`, assets}), 'assets');
}

export async function getAssets(jobId, item) {
    const row = await transaction('readonly', store => store.get(`${jobId}:${item}`), 'assets');
    return row?.assets || null;
}

export async function listResultMetadata(jobId) {
    const db = await database();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('results', 'readonly');
        const rows = [];
        const request = tx.objectStore('results').index('jobId').openCursor(IDBKeyRange.only(jobId));
        request.onsuccess = () => {
            const cursor = request.result;
            if (!cursor) return;
            const row = cursor.value;
            rows.push({item: row.item, quantity: row.quantity, sequence: row.sequence});
            cursor.continue();
        };
        tx.oncomplete = () => resolve(rows.sort((a, b) => a.sequence - b.sequence));
        tx.onerror = tx.onabort = () => reject(new Error('storage_failed'));
    });
}

export function saveResult(jobId, entry, raw, sequence) {
    return transaction('readwrite', store => store.put({key: `${jobId}:${entry.item}`, jobId,
        item: entry.item, quantity: entry.quantity, raw, sequence, capturedAt: new Date().toISOString()}));
}

export function getResult(jobId, item) {
    return transaction('readonly', store => store.get(`${jobId}:${item}`));
}

export function countResults(jobId) {
    return transaction('readonly', store => store.index('jobId').count(jobId));
}

export async function listResults(jobId) {
    const rows = await transaction('readonly', store => store.index('jobId').getAll(jobId));
    return rows.sort((a, b) => a.sequence - b.sequence);
}
