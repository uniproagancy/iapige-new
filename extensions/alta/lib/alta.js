// Reads alta.ge's own API from inside an alta.ge tab, the way the shop's
// pages call it themselves, so the browser's own session is the one used.
//
// When alta.ge answers with a check instead of data, nothing tries to get
// around it: collecting stops and the person passes the check in that tab.

import {ALTA} from './core.js';

const TAB_KEY = 'altaTab';

export class Blocked extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

// Runs in the alta.ge tab. Must stand alone: it is copied into the page.
function pageFetch(url) {
    return fetch(url, {
        credentials: 'include',
        headers: {'Accept': 'application/json, text/plain, */*', 'Accept-Language': 'ka'},
    }).then(async response => ({
        status: response.status,
        type: response.headers.get('content-type') || '',
        text: await response.text(),
    })).catch(error => ({status: 0, type: '', text: String(error)}));
}

export function createAltaReader({interval = 1200} = {}) {
    let last = 0;

    async function waitLoaded(tabId) {
        for (let i = 0; i < 120; i++) {
            const tab = await chrome.tabs.get(tabId);

            if (tab.status === 'complete') {
                return tab;
            }

            await sleep(250);
        }

        throw new Blocked('alta_tab_timeout');
    }

    async function tab() {
        const {[TAB_KEY]: saved} = await chrome.storage.session.get(TAB_KEY);

        if (Number.isInteger(saved)) {
            try {
                const current = await chrome.tabs.get(saved);

                if (current.url && new URL(current.url).origin === ALTA && !current.discarded) {
                    return current.status === 'complete' ? current : waitLoaded(current.id);
                }
            } catch {
                // Closed by hand; another is opened.
            }
        }

        const created = await chrome.tabs.create({url: `${ALTA}/`, active: false});
        // A background tab Chrome unloads to save memory cannot run anything.
        await chrome.tabs.update(created.id, {autoDiscardable: false});
        await chrome.storage.session.set({[TAB_KEY]: created.id});

        return waitLoaded(created.id);
    }

    async function getJson(url) {
        // One request at a time, no faster than a person clicking through.
        const wait = last + interval - Date.now();

        if (wait > 0) {
            await sleep(wait);
        }

        last = Date.now();

        const target = await tab();
        let result;

        try {
            [{result}] = await chrome.scripting.executeScript({target: {tabId: target.id}, func: pageFetch, args: [url]});
        } catch {
            throw new Blocked('alta_tab_unavailable');
        }

        if (result.status === 429) {
            throw new Blocked('alta_rate_limited');
        }

        if ([401, 403, 503].includes(result.status) || /text\/html/i.test(result.type)) {
            throw new Blocked('alta_check');
        }

        if (result.status === 404) {
            return null;
        }

        if (result.status < 200 || result.status >= 300) {
            throw new Error(`alta_http_${result.status}`);
        }

        try {
            return JSON.parse(result.text);
        } catch {
            throw new Blocked('alta_check');
        }
    }

    async function show() {
        const target = await tab();
        await chrome.tabs.update(target.id, {active: true});
        await chrome.windows.update(target.windowId, {focused: true});
    }

    return {getJson, show};
}
