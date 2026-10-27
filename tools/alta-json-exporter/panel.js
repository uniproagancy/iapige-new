import {parseCodes, parseFeed, candidateIds, exactDetails, toImportDocument, suggestionsUrl, detailsUrl} from './core.js';
import {createBrowserReader} from './browser.js';
import {saveResult, getResult, countResults, listResults} from './db.js';

const JOB_KEY = 'altaJsonExporterJob';
const FEED_KEY = 'altaJsonExporterFeed';
const reader = createBrowserReader();
const byId = id => document.getElementById(id);
const errors = {
    empty_input: 'ჩატვირთეთ B2B სია ან ჩასვით პროდუქტის კოდები.',
    invalid_limit: 'რაოდენობა უნდა იყოს 0 ან დადებითი მთელი რიცხვი.',
    invalid_item: 'პროდუქტის კოდი არასწორად არის მითითებული.',
    invalid_feed_json: 'ფაილში სწორი JSON არ არის.',
    invalid_feed: 'B2B სიის ფორმატი ვერ წავიკითხეთ. გადაამოწმეთ ფაილი ან შედით იაფის ადმინში.',
    invalid_feed_row: 'B2B სიის ერთ-ერთი ჩანაწერი არასწორია.',
    invalid_quantity: 'B2B მარაგი უნდა იყოს 0 ან დადებითი მთელი რიცხვი.',
    conflicting_quantity: 'ერთ კოდს სიაში განსხვავებული მარაგები აქვს. გადაამოწმეთ სია.',
    invalid_suggestions: 'Alta-ს ძიების JSON ვერ წავიკითხეთ. გადაამოწმეთ დამხმარე ჩანართი.',
    invalid_details: 'Alta-ს პროდუქტის JSON არასწორი ფორმით მოვიდა.',
    invalid_json: 'გვერდზე JSON ვერ წავიკითხეთ. გადაამოწმეთ დამხმარე ჩანართი და შემდეგ გააგრძელეთ.',
    challenge: 'გვერდმა ბრაუზერის შემოწმება მოითხოვა. შეგროვება შეჩერდა; შენახული შედეგები დარჩა.',
    navigation_timeout: 'დამხმარე ჩანართის ჩატვირთვას ვადა გაუვიდა. გადაამოწმეთ იგი და გააგრძელეთ.',
    script_timeout: 'დამხმარე ჩანართმა პასუხი დროულად არ დააბრუნა. გადაამოწმეთ იგი და გააგრძელეთ.',
    language_rule_failed: 'ქართული ენის მოთხოვნა ვერ დაყენდა. Alta-ს მოთხოვნა არ გაიგზავნა; განაახლეთ გაფართოება და გააგრძელეთ.',
    wrong_page: 'დამხმარე ჩანართი სხვა გვერდზე გადავიდა. იაფის სიისთვის შედით იაფის ადმინში.',
    navigation_failed: 'დამხმარე ჩანართის გახსნა ვერ მოხერხდა. სცადეთ გაგრძელება.',
    session_unavailable: 'დამხმარე ჩანართის მდგომარეობა ვერ შეინახა. დახურეთ ზედმეტი პანელები და სცადეთ თავიდან.',
    stock_unknown: 'იმპორტის JSON-ს B2B მარაგი სჭირდება. ჩატვირთეთ სია; საწყისი JSON უკვე ხელმისაწვდომია.',
    invalid_retail_price: 'ერთ პროდუქტს გამოსადეგი მიმდინარე ფასი არ აქვს. შეგიძლიათ საწყისი JSON ჩამოტვირთოთ.',
    missing_product_name: 'ერთ პროდუქტს დასახელება აკლია. შეგიძლიათ საწყისი JSON ჩამოტვირთოთ.',
    storage_failed: 'შედეგის შენახვა ვერ მოხერხდა. შეამოწმეთ ბრაუზერის თავისუფალი ადგილი.',
    storage_blocked: 'შედეგების საცავი სხვა პანელმა შეაფერხა. დახურეთ ზედმეტი პანელები.',
    already_running: 'სხვა Alta JSON პანელი უკვე მუშაობს. გააგრძელეთ იქ ან ჯერ შეაჩერეთ.',
    iapi_permission_denied: 'იაფის წვდომა არ დაუშვით. შეგიძლიათ JSON ფაილი ჩატვირთოთ ან კოდები ჩასვათ.',
    iapi_permission_failed: 'იაფის წვდომის მოთხოვნა ვერ შესრულდა. სცადეთ თავიდან ან გამოიყენეთ JSON ფაილი და კოდები.',
};
let feed = [];
let job = null;
let busy = false;
let initializing = true;
let permissionPending = false;
let activeToken = null;
let writes = Promise.resolve();

function say(message, error = false) {
    byId('message').textContent = message;
    byId('message').className = error ? 'error' : '';
}

function describe(error) {
    return errors[error?.message] || 'მოქმედება ვერ შესრულდა. შენახული შედეგები დარჩა; სცადეთ გაგრძელება.';
}

function persist(value) {
    const snapshot = structuredClone(value);
    writes = writes.catch(() => {}).then(() => chrome.storage.local.set({[JOB_KEY]: snapshot}));
    return writes;
}

function render() {
    const total = job?.items.length || 0;
    byId('position').textContent = `${job?.cursor || 0} / ${total}`;
    byId('collected').textContent = job?.collected || 0;
    byId('missing').textContent = job?.missing || 0;
    byId('progress').max = Math.max(1, total);
    byId('progress').value = job?.cursor || 0;
    for (const id of ['start', 'load-feed', 'feed-file', 'codes', 'limit']) byId(id).disabled = busy || initializing || permissionPending;
    byId('pause').disabled = !busy || activeToken === null;
    byId('resume').disabled = busy || initializing || permissionPending || !job || job.cursor >= total;
    byId('download-raw').disabled = !job?.collected;
    byId('download-import').disabled = !job?.collected;
    const known = feed.filter(row => row.quantity !== null).length;
    byId('feed-status').textContent = feed.length ? `ჩატვირთულია ${feed.length} კოდი; B2B მარაგი ცნობილია ${known}-ზე.` : 'B2B სია ჯერ არ არის ჩატვირთული.';
}

async function useFeed(rows) {
    const latest = await chrome.storage.local.get(JOB_KEY);
    job = latest[JOB_KEY] || null;
    feed = rows;
    await chrome.storage.local.set({[FEED_KEY]: rows});
    if (job) {
        const quantities = new Map(feed.map(row => [row.item, row.quantity]));
        job = {...job, items: job.items.map(row => quantities.has(row.item) ? {...row, quantity: quantities.get(row.item)} : row)};
        await persist(job);
    }
    render();
}

async function exclusive(action) {
    if (busy || initializing || permissionPending) return;
    busy = true;
    render();
    try {
        await navigator.locks.request('iapi-alta-json-exporter-browser', {ifAvailable: true}, async lock => {
            if (!lock) throw new Error('already_running');
            await action();
        });
    } catch (error) {
        say(describe(error), true);
    } finally {
        busy = false;
        activeToken = null;
        render();
    }
}

async function collect(resume) {
    await exclusive(async () => {
        const token = {};
        activeToken = token;
        if (!resume) {
            const quantities = new Map(feed.map(row => [row.item, row.quantity]));
            const pasted = byId('codes').value.trim();
            let items = pasted ? parseCodes(pasted).map(row => ({...row, quantity: quantities.get(row.item) ?? null})) : feed;
            const limit = Number(byId('limit').value);
            if (!Number.isSafeInteger(limit) || limit < 0) throw new Error('invalid_limit');
            if (limit > 0) items = items.slice(0, limit);
            if (!items.length) throw new Error('empty_input');
            job = {version: 1, id: crypto.randomUUID(), items, cursor: 0, collected: 0, missing: 0,
                status: 'running', createdAt: new Date().toISOString()};
        } else {
            const saved = await chrome.storage.local.get(JOB_KEY);
            job = saved[JOB_KEY];
            if (!job || job.cursor >= job.items.length) throw new Error('empty_input');
            job = {...job, status: 'running'};
        }
        let current = structuredClone(job);
        await persist(current);
        render();
        try {
            for (; current.cursor < current.items.length;) {
                if (activeToken !== token) return;
                const entry = current.items[current.cursor];
                say(`იკითხება ${entry.item} · ${current.cursor + 1} / ${current.items.length}`);
                let saved = await getResult(current.id, entry.item);
                if (activeToken !== token) return;
                if (!saved) {
                    const suggestions = await reader.readJson(suggestionsUrl(entry.item));
                    if (activeToken !== token) return;
                    let matched = null;
                    for (const id of candidateIds(suggestions, entry.item)) {
                        const details = await reader.readJson(detailsUrl(id));
                        if (activeToken !== token) return;
                        if (Number(details?.product?.id) !== id) throw new Error('invalid_details');
                        matched = exactDetails(details, entry.item);
                        if (matched) break;
                    }
                    if (matched) {
                        await saveResult(current.id, entry, matched, current.cursor);
                        if (activeToken !== token) return;
                        saved = true;
                    }
                }
                current = {...current, cursor: current.cursor + 1,
                    collected: saved ? await countResults(current.id) : current.collected,
                    missing: current.missing + (saved ? 0 : 1)};
                if (activeToken !== token) return;
                job = current;
                await persist(job);
                render();
            }
            if (activeToken !== token) return;
            job = {...current, status: 'complete'};
            await persist(job);
            say(`დასრულდა: შენახულია ${job.collected}; ვერ მოიძებნა ${job.missing}. შეგიძლიათ JSON ჩამოტვირთოთ.`);
        } catch (error) {
            if (activeToken !== token) return;
            job = {...current, status: 'paused', lastError: error?.message || 'unexpected'};
            await persist(job);
            say(describe(error), true);
        }
    });
}

async function download(raw) {
    try {
        const snapshot = structuredClone(job);
        const rows = await listResults(snapshot.id);
        const quantities = new Map(snapshot.items.map(row => [row.item, row.quantity]));
        const documents = rows.map(row => raw ? row.raw : toImportDocument(row.raw,
            {item: row.item, quantity: quantities.has(row.item) ? quantities.get(row.item) : row.quantity}));
        const blob = new Blob([JSON.stringify(documents, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `alta-${raw ? 'raw' : 'import'}-${snapshot.id.slice(0, 8)}.json`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 30_000);
        byId('download-status').textContent = `${documents.length} პროდუქტის JSON მზადაა. არსებული ხელით ამტვირთავი გამოიყენეთ.`;
    } catch (error) {
        say(describe(error), true);
    }
}

byId('start').addEventListener('click', () => void collect(false));
byId('resume').addEventListener('click', () => void collect(true));
byId('pause').addEventListener('click', () => void (async () => {
    activeToken = null;
    if (job) {
        job = {...job, status: 'paused'};
        await persist(job);
    }
    say('შეჩერებულია. შედეგები შენახულია; შეგიძლიათ ჩამოტვირთოთ ან გააგრძელოთ.');
    render();
})());
byId('load-feed').addEventListener('click', () => {
    if (busy || initializing || permissionPending) return;
    permissionPending = true;
    render();
    let request;
    try {
        // Chrome requires this call in the click's user gesture, before any await.
        request = chrome.permissions.request({origins: ['https://iapi.ge/*']});
    } catch {
        permissionPending = false;
        say(errors.iapi_permission_failed, true);
        render();
        return;
    }
    void Promise.resolve(request).then(granted => {
        permissionPending = false;
        if (!granted) {
            say(errors.iapi_permission_denied, true);
            return;
        }
        return exclusive(async () => {
            say('B2B სია იკითხება. ამავე ბრაუზერში იაფის ადმინში უნდა იყოთ შესული.');
            const data = await reader.readJson('https://iapi.ge/admin/alta-browser/feed?include_out_of_stock=1');
            await useFeed(parseFeed(data));
            say('B2B სია ჩატვირთულია. შეგიძლიათ დაიწყოთ ან კონკრეტული კოდები ჩასვათ.');
        });
    }).catch(() => say(errors.iapi_permission_failed, true)).finally(() => {
        permissionPending = false;
        render();
    });
});
byId('feed-file').addEventListener('change', () => void exclusive(async () => {
        const file = byId('feed-file').files[0];
        if (file) await useFeed(parseFeed(await file.text()));
        say('B2B სიის ფაილი ჩატვირთულია.');
}));
byId('download-import').addEventListener('click', () => void download(false));
byId('download-raw').addEventListener('click', () => void download(true));
window.addEventListener('beforeunload', event => {
    if (!busy) return;
    event.preventDefault();
    event.returnValue = '';
});

render();
void (async () => {
    const saved = await chrome.storage.local.get([JOB_KEY, FEED_KEY]);
    feed = saved[FEED_KEY] || [];
    job = saved[JOB_KEY] || null;
    if (job) {
        job.collected = await countResults(job.id);
        say(job.cursor < job.items.length ? 'შენახული სამუშაო ხელმისაწვდომია. დააჭირეთ „გაგრძელებას“.' : 'შენახული შედეგები მზადაა ჩამოსატვირთად.');
    }
})().catch(error => say(describe(error), true)).finally(() => {
    initializing = false;
    render();
});
