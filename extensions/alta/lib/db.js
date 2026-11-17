// Everything collected stays in the browser until it is downloaded, so a
// closed panel or a restarted computer loses nothing: the work goes on from
// where it stopped.

const NAME = 'iapi-alta';
const VERSION = 1;

let opening = null;

function open() {
    opening ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(NAME, VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            const items = db.createObjectStore('items', {keyPath: 'code'});
            items.createIndex('order', 'order');
            db.createObjectStore('meta');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return opening;
}

function done(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function store(name, mode) {
    return (await open()).transaction(name, mode).objectStore(name);
}

export async function getMeta(key) {
    return done((await store('meta', 'readonly')).get(key));
}

export async function setMeta(key, value) {
    return done((await store('meta', 'readwrite')).put(value, key));
}

export async function getItem(code) {
    return done((await store('items', 'readonly')).get(code));
}

export async function putItem(item) {
    return done((await store('items', 'readwrite')).put(item));
}

// A new list replaces the old one whole, in one transaction.
export async function replaceItems(items) {
    const db = await open();

    await new Promise((resolve, reject) => {
        const tx = db.transaction('items', 'readwrite');
        const items$ = tx.objectStore('items');
        items$.clear();
        items.forEach(item => items$.put(item));
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

// Walks the list in its own order, a light summary at a time: a record with
// its photos is a few megabytes, and the counts need none of that.
export async function summaries() {
    const db = await open();

    return new Promise((resolve, reject) => {
        const rows = [];
        const request = db.transaction('items', 'readonly').objectStore('items').index('order').openCursor();

        request.onsuccess = () => {
            const cursor = request.result;

            if (!cursor) {
                resolve(rows);

                return;
            }

            const {code, order, quantity, onSite, status, error, warning, photos} = cursor.value;
            rows.push({code, order, quantity, onSite, status, error, warning, photos});
            cursor.continue();
        };
        request.onerror = () => reject(request.error);
    });
}
