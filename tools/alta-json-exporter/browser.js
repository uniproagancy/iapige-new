import {validateFeedUrl} from './core.js';

const SESSION_KEY = 'altaJsonExporterHelper';
const ORIGINS = new Set(['https://alta.ge', 'https://iapi.ge']);
export const LANGUAGE_RULE_ID = 761031;
export const LANGUAGE_RULE_PATTERN = '^https://alta\\.ge/api/proxy/v1/Products/(search/suggestions|details)\\?';

function allowedUrl(value) {
    let url;
    try { url = new URL(value); } catch { throw new Error('wrong_page'); }
    if (!ORIGINS.has(url.origin) || url.username || url.password) throw new Error('wrong_page');
    if (url.origin === 'https://iapi.ge') return validateFeedUrl(value);
    url.hash = '';
    return url.href;
}

function bounded(action, milliseconds, code) {
    let timer;
    return Promise.race([
        Promise.resolve().then(action),
        new Promise((resolve, reject) => { timer = setTimeout(() => reject(new Error(code)), Math.max(1, milliseconds)); }),
    ]).finally(() => clearTimeout(timer));
}

export function readRenderedJson(expectedUrl) {
    const text = document.querySelector('pre')?.innerText || document.querySelector('pre')?.textContent || document.body?.innerText || '';
    if (/Just a moment|Attention Required|Cloudflare/i.test(document.title || '')
        || /verify you are human|checking your browser|performing security verification/i.test(text.slice(0, 4000))) {
        return {ok: false, code: 'challenge'};
    }
    try {
        const actual = new URL(location.href), expected = new URL(expectedUrl);
        if (!['https://alta.ge', 'https://iapi.ge'].includes(actual.origin)
            || actual.origin !== expected.origin || actual.pathname !== expected.pathname || actual.search !== expected.search) {
            return {ok: false, code: 'wrong_page'};
        }
    } catch { return {ok: false, code: 'wrong_page'}; }
    try { return {ok: true, data: JSON.parse(text.trim())}; }
    catch { return {ok: false, code: 'invalid_json'}; }
}

export function createBrowserReader(chromeApi = chrome, {
    timeoutMs = 25_000, injectionTimeoutMs = 10_000, minIntervalMs = 1000, pollMs = 250,
} = {}) {
    let helperTabId = null;
    let ownPlaceholder = false;
    let lastNavigationAt = 0;
    let pending = Promise.resolve();
    const api = (action, remaining = () => timeoutMs) => bounded(action, remaining(), 'navigation_timeout');
    const sleep = delay => new Promise(resolve => setTimeout(resolve, delay));

    async function remember(remaining) {
        await api(() => chromeApi.storage.session.set({[SESSION_KEY]: {tabId: helperTabId, lastNavigationAt,
            placeholder: ownPlaceholder}}), remaining);
    }

    async function languageRule(add, remaining) {
        try {
            await bounded(() => chromeApi.declarativeNetRequest.updateSessionRules({
                removeRuleIds: [LANGUAGE_RULE_ID],
                addRules: add ? [{
                    id: LANGUAGE_RULE_ID,
                    priority: 1,
                    action: {type: 'modifyHeaders', requestHeaders: [{header: 'accept-language', operation: 'set', value: 'ka'}]},
                    condition: {tabIds: [helperTabId], resourceTypes: ['main_frame'], regexFilter: LANGUAGE_RULE_PATTERN,
                        isUrlFilterCaseSensitive: true},
                }] : [],
            }), remaining(), 'language_rule_failed');
        } catch {
            throw new Error('language_rule_failed');
        }
    }

    async function ownedTab(remaining) {
        if (!chromeApi.storage?.session) throw new Error('session_unavailable');
        if (helperTabId === null) {
            const record = (await api(() => chromeApi.storage.session.get(SESSION_KEY), remaining))[SESSION_KEY];
            if (Number.isInteger(record?.tabId)) {
                helperTabId = record.tabId;
                ownPlaceholder = record.placeholder === true;
                lastNavigationAt = Number.isFinite(record.lastNavigationAt) ? record.lastNavigationAt : 0;
            }
        }
        if (helperTabId !== null) {
            try {
                const tab = await api(() => chromeApi.tabs.get(helperTabId), remaining);
                const address = tab.url || tab.pendingUrl;
                if (address !== 'about:blank' && !(ownPlaceholder && !address)) allowedUrl(address);
                if (!tab.discarded && !tab.frozen) return tab;
            } catch (error) {
                if (error.message === 'navigation_timeout') throw error;
            }
            await languageRule(false, remaining);
            helperTabId = null;
            ownPlaceholder = false;
            await api(() => chromeApi.storage.session.remove(SESSION_KEY), remaining);
        }
        return null;
    }

    async function navigate(url) {
        const deadline = Date.now() + timeoutMs;
        const remaining = () => Math.max(1, deadline - Date.now());
        const pace = async () => {
            const wait = Math.max(0, lastNavigationAt + minIntervalMs - Date.now());
            if (wait > 0) await sleep(Math.min(wait, remaining()));
            if (Date.now() >= deadline) throw new Error('navigation_timeout');
            lastNavigationAt = Date.now();
        };
        const tab = await ownedTab(remaining);
        const previousUrl = tab?.url || tab?.pendingUrl || 'about:blank';
        if (!tab) {
            await pace();
            const created = await bounded(() => chromeApi.tabs.create({url: 'about:blank', active: false}), remaining(), 'navigation_timeout');
            if (!Number.isInteger(created?.id)) throw new Error('navigation_failed');
            helperTabId = created.id;
            ownPlaceholder = true;
            await remember(remaining);
        }
        await languageRule(new RegExp(LANGUAGE_RULE_PATTERN).test(url), remaining);
        await pace();
        ownPlaceholder = false;
        await remember(remaining);
        await bounded(() => chromeApi.tabs.update(helperTabId, {url}), remaining(), 'navigation_timeout');
        let targetSeen = false;
        do {
            const current = await bounded(() => chromeApi.tabs.get(helperTabId), remaining(), 'navigation_timeout');
            if (/Just a moment|Attention Required|Cloudflare/i.test(current.title || '')) throw new Error('challenge');
            let currentUrl = null;
            try { const parsed = new URL(current.url); parsed.hash = ''; currentUrl = parsed.href; } catch { /* Navigation may not have committed yet. */ }
            const matchesTarget = currentUrl === url;
            if (matchesTarget) targetSeen = true;
            if (current.status === 'complete') {
                if (matchesTarget) {
                    if (current.discarded || current.frozen) throw new Error('navigation_timeout');
                    return;
                }
                // tabs.update can resolve before Chrome replaces the prior committed document.
                if (currentUrl && currentUrl !== 'about:blank' && (targetSeen || currentUrl !== previousUrl)) {
                    throw new Error('wrong_page');
                }
            }
            if (Date.now() >= deadline) break;
            await sleep(Math.min(pollMs, remaining()));
        } while (Date.now() < deadline);
        throw new Error('navigation_timeout');
    }

    async function read(value) {
        const url = allowedUrl(value);
        await navigate(url);
        const results = await bounded(() => chromeApi.scripting.executeScript({
            target: {tabId: helperTabId}, world: 'ISOLATED', func: readRenderedJson, args: [url],
        }), injectionTimeoutMs, 'script_timeout');
        const result = results[0]?.result;
        if (!result?.ok) throw new Error(result?.code || 'invalid_json');
        return result.data;
    }

    return {
        readJson(url) {
            const task = pending.then(() => read(url));
            pending = task.catch(() => {});
            return task;
        },
        getHelperTabId() { return helperTabId; },
    };
}
