// The stock list comes from iapi.ge under the admin's own sign-in: no link
// to keep safe or renew, and whoever is not signed in as an admin gets
// nothing.

import {parseFeed} from './core.js';

const SITE = 'https://iapi.ge';
const FEED = `${SITE}/admin/alta-browser/feed`;

export class SiteError extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

// Runs in an iapi.ge tab. Must stand alone: it is copied into the page.
function pageFetch(url) {
    return fetch(url, {credentials: 'include', headers: {Accept: 'application/json'}})
        .then(async response => ({status: response.status, text: await response.text()}))
        .catch(error => ({status: 0, text: String(error)}));
}

async function direct() {
    try {
        const response = await fetch(FEED, {credentials: 'include', headers: {Accept: 'application/json'}});

        return {status: response.status, text: await response.text()};
    } catch (error) {
        return {status: 0, text: String(error)};
    }
}

// Should the browser keep the sign-in cookie from the extension's own
// request, the same request is made from an iapi.ge page instead.
async function throughTab() {
    const tab = await chrome.tabs.create({url: `${SITE}/admin`, active: false});

    try {
        for (let i = 0; i < 120; i++) {
            if ((await chrome.tabs.get(tab.id)).status === 'complete') {
                break;
            }

            await new Promise(resolve => setTimeout(resolve, 250));
        }

        const [{result}] = await chrome.scripting.executeScript({target: {tabId: tab.id}, func: pageFetch, args: [FEED]});

        return result;
    } finally {
        chrome.tabs.remove(tab.id).catch(() => {});
    }
}

function read(answer) {
    let data = null;

    try {
        data = JSON.parse(answer.text);
    } catch {
        // Handled below by status.
    }

    if (answer.status === 200 && data) {
        return parseFeed(data);
    }

    if (answer.status === 401 || answer.status === 419) throw new SiteError('site_signed_out');
    if (answer.status === 403) throw new SiteError('site_forbidden');
    if (answer.status === 429) throw new SiteError('site_rate_limited');
    if (data?.error === 'stock_snapshot_stale') throw new SiteError('site_stock_stale');
    if (answer.status === 503) throw new SiteError('site_stock_unavailable');
    if (answer.status === 0) throw new SiteError('site_offline');

    throw new SiteError('site_failed');
}

export async function loadFeed() {
    const first = await direct();

    if (first.status === 401 || first.status === 419 || (first.status === 200 && !first.text.trim().startsWith('{'))) {
        return read(await throughTab());
    }

    return read(first);
}
