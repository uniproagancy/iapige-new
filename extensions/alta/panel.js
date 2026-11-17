import {buildRecord, candidates, detailsUrl, matches, photoUrls, pick, suggestionsUrl} from './lib/core.js';
import {getItem, getMeta, putItem, replaceItems, setMeta, summaries} from './lib/db.js';
import {Blocked, createAltaReader} from './lib/alta.js';
import {collectPhotos} from './lib/photos.js';
import {loadFeed, SiteError} from './lib/site.js';
import {T} from './lib/strings.js';

const $ = id => document.getElementById(id);
const reader = createAltaReader();

let feed = null;
let running = false;
let stopping = false;

// ---------- the list ----------

function when(iso) {
    const date = iso ? new Date(iso) : null;

    return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('ka-GE') : '—';
}

function showFeed() {
    if (!feed) {
        $('feed-info').textContent = T.feed_none;

        return;
    }

    const held = feed.items.filter(item => item.onSite).length;
    $('feed-info').textContent = T.feed_loaded(feed.items.length, feed.items.length - held, held, when(feed.syncedAt));
}

$('load-feed').addEventListener('click', async () => {
    $('load-feed').disabled = true;
    $('feed-info').textContent = T.feed_loading;
    alert(null);

    try {
        feed = await loadFeed();
        await setMeta('feed', feed);
    } catch (error) {
        alert(error instanceof SiteError ? T[error.code] : T.site_failed);
    } finally {
        $('load-feed').disabled = false;
        showFeed();
    }
});

// ---------- collecting ----------

async function collect(item, job) {
    const suggestions = await reader.getJson(suggestionsUrl(item.code));

    if (!suggestions) {
        return {status: 'not_found'};
    }

    let details = null;

    for (const id of candidates(suggestions, item.code)) {
        const answer = await reader.getJson(detailsUrl(id));

        if (answer && matches(answer, item.code)) {
            details = answer;
            break;
        }
    }

    if (!details) {
        return {status: 'not_found'};
    }

    const record = buildRecord(details, item.quantity);
    let warning = null;
    let photos = 0;

    if (!item.onSite || job.photosHeld) {
        const {urls, usable} = photoUrls(record.product);

        if (!urls.length) {
            warning = 'photos_none';
        } else if (!usable) {
            warning = 'photos_links';
        } else {
            try {
                record.iapiAssets = await collectPhotos(urls);
                photos = urls.length;
            } catch (error) {
                warning = error.message === 'photos_too_large' ? 'photos_too_large' : 'photos_failed';
            }
        }
    }

    return {status: 'done', record, warning, photos};
}

// Product faults: this product cannot be had, the next one may well be.
const PRODUCT_FAULTS = ['no_name', 'no_price', 'invalid_answer'];

async function collectPatiently(item, job) {
    // A slowed-down alta is waited out a couple of times before giving up.
    for (let attempt = 0; ; attempt++) {
        try {
            return await collect(item, job);
        } catch (error) {
            if (!(error instanceof Blocked) || error.code !== 'alta_rate_limited' || attempt >= 2) {
                throw error;
            }

            await countdown(90);
        }
    }
}

async function run() {
    if (running) {
        return;
    }

    running = true;
    stopping = false;
    alert(null);

    const job = await getMeta('job');
    job.state = 'running';
    await setMeta('job', job);
    await render();

    let failures = 0;

    try {
        for (const row of await summaries()) {
            if (stopping) {
                break;
            }

            if (row.status !== 'pending') {
                continue;
            }

            $('state').textContent = T.state_running(row.code);
            const item = await getItem(row.code);
            let result;

            try {
                result = await collectPatiently(item, job);
                failures = 0;
            } catch (error) {
                if (error instanceof Blocked) {
                    // The product is left pending and is the first one tried
                    // when collecting goes on.
                    stopping = true;
                    alert(T[error.code]);

                    if (error.code === 'alta_check') {
                        reader.show().catch(() => {});
                    }

                    break;
                }

                result = {status: 'error', error: error?.message || 'error'};

                // Several unexplained failures in a row mean alta, not the
                // products; going on would only mark the whole list failed.
                if (!PRODUCT_FAULTS.includes(result.error) && ++failures >= 5) {
                    stopping = true;
                    alert(T.too_many_errors);
                }
            }

            Object.assign(item, {record: null, warning: null, error: null, photos: 0}, result);
            await putItem(item);
            await renderCounts();
        }
    } finally {
        running = false;
    }

    const left = (await summaries()).some(row => row.status === 'pending');
    job.state = left ? 'paused' : 'done';
    await setMeta('job', job);
    await render();
}

async function countdown(seconds) {
    for (let left = seconds; left > 0 && !stopping; left--) {
        $('state').textContent = T.state_waiting(left);
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
}

$('start').addEventListener('click', async () => {
    if (!feed) {
        alert(T.need_feed);

        return;
    }

    const mode = document.querySelector('input[name="mode"]:checked').value;
    const chosen = pick(feed.items, mode, Math.max(0, Number($('limit').value) || 0));

    if (!chosen.length) {
        alert(T.nothing_chosen);

        return;
    }

    const rows = await summaries();
    const job = await getMeta('job');
    const done = rows.filter(row => row.status === 'done').length;
    const downloaded = job?.downloadedCount ?? 0;

    if (done > downloaded && !confirm(T.confirm_new(done - downloaded))) {
        return;
    }

    await replaceItems(chosen.map((item, order) => ({...item, order, status: 'pending'})));
    await setMeta('job', {
        createdAt: new Date().toISOString(),
        syncedAt: feed.syncedAt,
        mode,
        photosHeld: $('photos-held').checked,
        total: chosen.length,
        state: 'paused',
        downloadedCount: 0,
    });

    run();
});

$('pause').addEventListener('click', () => {
    stopping = true;
    $('pause').disabled = true;
});

$('resume').addEventListener('click', () => run());

$('retry').addEventListener('click', async () => {
    for (const row of await summaries()) {
        if (row.status === 'error') {
            const item = await getItem(row.code);
            item.status = 'pending';
            item.error = null;
            await putItem(item);
        }
    }

    run();
});

// ---------- downloading ----------

function stamp(iso) {
    const date = new Date(iso);
    const pad = value => String(value).padStart(2, '0');

    return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}-${pad(date.getHours())}${pad(date.getMinutes())}`;
}

// Written to disk a product at a time, the whole list in one file: with
// photos it runs to hundreds of megabytes, more than one string can hold.
async function write(codes, put) {
    await put('[');

    for (let i = 0; i < codes.length; i++) {
        const item = await getItem(codes[i]);
        await put((i ? ',' : '') + JSON.stringify(item.record));

        if (i % 20 === 0) {
            $('download').textContent = T.writing(i + 1, codes.length);
        }
    }

    await put(']');
}

$('download').addEventListener('click', async () => {
    const job = await getMeta('job');
    const codes = (await summaries()).filter(row => row.status === 'done').map(row => row.code);
    const name = `alta-${stamp(job.createdAt)}.json`;

    if (!codes.length) {
        return;
    }

    $('download').disabled = true;

    try {
        if (window.showSaveFilePicker) {
            // Straight into the chosen file, so a large list never has to
            // sit in memory whole.
            let handle;

            try {
                handle = await window.showSaveFilePicker({suggestedName: name, types: [{description: 'JSON', accept: {'application/json': ['.json']}}]});
            } catch {
                return; // The save dialog was closed.
            }

            const file = await handle.createWritable();

            try {
                await write(codes, text => file.write(text));
                await file.close();
            } catch (error) {
                await file.abort().catch(() => {});
                throw error;
            }
        } else {
            const pieces = [];
            await write(codes, text => pieces.push(text));
            const url = URL.createObjectURL(new Blob(pieces, {type: 'application/json'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        }

        job.downloadedCount = codes.length;
        await setMeta('job', job);
    } catch {
        alert(T.download_failed);
    } finally {
        await renderDownload();
    }
});

// ---------- drawing ----------

function alert(message) {
    $('alert').hidden = !message;
    $('alert').textContent = message || '';
}

async function renderCounts() {
    const rows = await summaries();
    const count = status => rows.filter(row => row.status === status).length;
    const done = count('done');
    const missing = count('not_found');
    const failed = count('error');
    const left = count('pending');

    $('progress').max = Math.max(1, rows.length);
    $('progress').value = rows.length - left;
    $('counts').innerHTML = '';

    for (const [label, value] of [[T.count_done, done], [T.count_missing, missing], [T.count_error, failed], [T.count_left, left]]) {
        const li = document.createElement('li');
        const b = document.createElement('b');
        b.textContent = value;
        li.append(b, label);
        $('counts').append(li);
    }

    const problems = rows.filter(row => row.status === 'not_found' || row.status === 'error' || row.warning);
    $('problem-count').textContent = problems.length ? `(${problems.length})` : '';
    $('problems').innerHTML = '';

    for (const row of problems.slice(-300)) {
        const li = document.createElement('li');
        const code = document.createElement('code');
        code.textContent = row.code;
        const why = row.status === 'not_found' ? 'not_found' : (row.error || row.warning);
        li.append(code, ' — ', T.problem[why] || why);
        $('problems').append(li);
    }

    $('retry').hidden = running || !failed;
}

async function renderDownload() {
    const job = await getMeta('job');
    const done = (await summaries()).filter(row => row.status === 'done').length;
    const button = $('download');

    button.disabled = running || !done;
    button.textContent = done ? T.download(done) : T.parts_none;
    $('download-info').textContent = running && done ? T.download_after_pause
        : (job?.downloadedCount && job.downloadedCount === done ? T.downloaded : '');
}

async function render() {
    const job = await getMeta('job');
    const left = job ? (await summaries()).some(row => row.status === 'pending') : false;

    $('start').disabled = running;
    $('load-feed').disabled = running;
    $('pause').hidden = !running;
    $('pause').disabled = false;
    $('resume').hidden = running || !left;

    if (!running) {
        $('state').textContent = !job ? T.state_idle : (left ? T.state_paused : T.state_done);
    }

    await renderCounts();
    await renderDownload();
}

// ---------- start ----------

(async () => {
    feed = await getMeta('feed') ?? null;
    showFeed();

    // A panel closed while collecting comes back paused, ready to go on.
    const job = await getMeta('job');

    if (job?.state === 'running') {
        job.state = 'paused';
        await setMeta('job', job);
    }

    await render();
})();
