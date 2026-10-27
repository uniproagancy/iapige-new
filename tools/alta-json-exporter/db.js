let opening;

function database() {
    if (!opening) opening = new Promise((resolve, reject) => {
        const request = indexedDB.open('iapi-alta-json-exporter', 1);
        request.onupgradeneeded = () => {
            const store = request.result.createObjectStore('results', {keyPath: 'key'});
            store.createIndex('jobId', 'jobId');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(new Error('storage_failed'));
        request.onblocked = () => reject(new Error('storage_blocked'));
    });
    return opening;
}

async function transaction(mode, action) {
    const db = await database();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('results', mode);
        let result;
        const request = action(tx.objectStore('results'));
        request.onsuccess = () => { result = request.result; };
        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(new Error('storage_failed'));
        tx.onabort = () => reject(new Error('storage_failed'));
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
