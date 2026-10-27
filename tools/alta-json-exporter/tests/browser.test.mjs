import test from 'node:test';
import assert from 'node:assert/strict';
import {createBrowserReader, readRenderedJson, LANGUAGE_RULE_ID, LANGUAGE_RULE_PATTERN} from '../browser.js';

const FIRST = 'https://alta.ge/api/proxy/v1/Products/search/suggestions?query=001234';
const SECOND = 'https://alta.ge/api/proxy/v1/Products/details?productId=741';
const FEED_LINK = `https://iapi.ge/feeds/alta-stock.json?version=11111111-1111-4111-8111-111111111111&signature=${'a'.repeat(64)}`;
const KEY = 'altaJsonExporterHelper';

function fixture() {
    const session = {}, tabs = new Map(), navigations = [], injections = [], ruleUpdates = [], operations = [];
    let nextId = 10;
    const chrome = {
        storage: {session: {
            get: async key => ({[key]: session[key]}),
            set: async values => { operations.push({kind: 'session'}); Object.assign(session, values); },
            remove: async key => { delete session[key]; },
        }},
        tabs: {
            get: async id => { if (!tabs.has(id)) throw new Error('No tab'); return tabs.get(id); },
            create: async options => {
                const tab = {id: nextId++, url: options.url, status: 'complete'};
                tabs.set(tab.id, tab); const operation = {kind: 'create', ...options, at: Date.now()};
                navigations.push(operation); operations.push(operation); return tab;
            },
            update: async (id, options) => {
                const tab = {id, url: options.url, status: 'complete'};
                tabs.set(id, tab); const operation = {kind: 'update', id, ...options, at: Date.now()};
                navigations.push(operation); operations.push(operation); return tab;
            },
        },
        declarativeNetRequest: {updateSessionRules: async options => {
            ruleUpdates.push(structuredClone(options)); operations.push({kind: 'rule', options: structuredClone(options)});
        }},
        scripting: {executeScript: async options => {
            injections.push(options);
            return [{result: {ok: true, data: {raw: options.args[0]}}}];
        }},
    };
    return {chrome, session, tabs, navigations, injections, ruleUpdates, operations};
}

test('reader creates one owned inactive helper and uses only normal navigations and rendered JSON', async () => {
    const f = fixture();
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0});
    assert.deepEqual(await reader.readJson(FIRST), {raw: FIRST});
    assert.deepEqual(await reader.readJson(SECOND), {raw: SECOND});
    assert.equal(f.navigations.length, 3);
    assert.equal(f.navigations[0].kind, 'create');
    assert.equal(f.navigations[0].url, 'about:blank');
    assert.equal(f.navigations[0].active, false);
    assert.equal(f.navigations[1].kind, 'update');
    assert.equal(f.navigations[1].id, reader.getHelperTabId());
    assert.equal(f.navigations[1].url, FIRST);
    assert.equal(f.navigations[2].url, SECOND);
    assert.equal(f.session[KEY].tabId, reader.getHelperTabId());
    assert.equal(f.injections[0].world, 'ISOLATED');
    assert.equal(f.injections[0].func, readRenderedJson);
});

test('reader restores its own session helper but never navigates a foreign or frozen tab', async () => {
    const f = fixture();
    f.session[KEY] = {tabId: 7, lastNavigationAt: 0};
    f.tabs.set(7, {id: 7, url: 'https://foreign.invalid/', status: 'complete'});
    await createBrowserReader(f.chrome, {minIntervalMs: 0}).readJson(FIRST);
    assert.equal(f.navigations[0].kind, 'create');
    assert.equal(f.tabs.get(7).url, 'https://foreign.invalid/');
    const owned = f.session[KEY].tabId;
    await createBrowserReader(f.chrome, {minIntervalMs: 0}).readJson(SECOND);
    assert.equal(f.navigations[2].id, owned);
    f.tabs.get(owned).frozen = true;
    await createBrowserReader(f.chrome, {minIntervalMs: 0}).readJson(FIRST);
    assert.equal(f.navigations[3].kind, 'create');
});

test('concurrent requests remain serialized with the configured minimum navigation interval', async () => {
    const f = fixture();
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 35});
    await Promise.all([reader.readJson(FIRST), reader.readJson(SECOND)]);
    const requests = f.navigations.filter(row => row.url !== 'about:blank');
    assert.ok(requests[1].at - requests[0].at >= 33);
});

test('new helper is blank and persisted until the language rule succeeds before the first Alta request', async () => {
    const f = fixture();
    let release;
    f.chrome.declarativeNetRequest.updateSessionRules = options => new Promise(resolve => {
        f.ruleUpdates.push(structuredClone(options));
        release = resolve;
    });
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0});
    const reading = reader.readJson(FIRST);
    await until(() => typeof release === 'function');
    assert.deepEqual(f.navigations.map(row => row.url), ['about:blank']);
    assert.equal(f.session[KEY].tabId, reader.getHelperTabId());
    release();
    assert.deepEqual(await reading, {raw: FIRST});
    assert.equal(f.navigations[1].url, FIRST);
    const rule = f.ruleUpdates[0].addRules[0];
    assert.equal(rule.id, LANGUAGE_RULE_ID);
    assert.deepEqual(rule.condition.tabIds, [reader.getHelperTabId()]);
    assert.deepEqual(rule.condition.resourceTypes, ['main_frame']);
    assert.deepEqual(rule.action, {type: 'modifyHeaders', requestHeaders: [{header: 'accept-language', operation: 'set', value: 'ka'}]});
});

test('session language rule is reapplied for restored helpers and retargeted after a closed helper', async () => {
    const f = fixture();
    const first = createBrowserReader(f.chrome, {minIntervalMs: 0});
    await first.readJson(FIRST);
    const owned = first.getHelperTabId();
    await createBrowserReader(f.chrome, {minIntervalMs: 0}).readJson(SECOND);
    assert.deepEqual(f.ruleUpdates.filter(row => row.addRules?.length).map(row => row.addRules[0].condition.tabIds), [[owned], [owned]]);
    f.tabs.delete(owned);
    const next = createBrowserReader(f.chrome, {minIntervalMs: 0});
    await next.readJson(FIRST);
    assert.notEqual(next.getHelperTabId(), owned);
    assert.deepEqual(f.ruleUpdates.at(-1).addRules[0].condition.tabIds, [next.getHelperTabId()]);
    assert.ok(f.ruleUpdates.every(row => row.removeRuleIds.length === 1 && row.removeRuleIds[0] === LANGUAGE_RULE_ID));
});

test('language header scope matches only the two exact HTTPS Alta API paths and owned main-frame tab', async () => {
    const f = fixture();
    await createBrowserReader(f.chrome, {minIntervalMs: 0}).readJson(FIRST);
    const rule = f.ruleUpdates.find(row => row.addRules?.length).addRules[0];
    assert.equal(rule.condition.regexFilter, LANGUAGE_RULE_PATTERN);
    assert.equal(rule.condition.isUrlFilterCaseSensitive, true);
    const pattern = new RegExp(rule.condition.regexFilter);
    for (const url of [FIRST, SECOND]) assert.ok(pattern.test(url), url);
    for (const url of ['http://alta.ge/api/proxy/v1/Products/details?productId=741',
        'https://sub.alta.ge/api/proxy/v1/Products/details?productId=741',
        'https://alta.ge.evil.invalid/api/proxy/v1/Products/details?productId=741',
        'https://iapi.ge/api/proxy/v1/Products/details?productId=741',
        'https://alta.ge/api/proxy/v1/Products/details-extra?productId=741',
        'https://alta.ge/api/proxy/v1/Products/DETAILS?productId=741',
        'https://alta.ge/api/proxy/v1/Products/details']) assert.equal(pattern.test(url), false, url);
    assert.deepEqual(rule.condition.resourceTypes, ['main_frame']);
    assert.equal(rule.condition.tabIds.length, 1);
});

test('failed and timed-out language rule installation prevents Alta navigation and leaves a reusable blank helper', async () => {
    for (const failure of ['throw', 'reject', 'timeout']) {
        const f = fixture();
        let releaseLate;
        f.chrome.declarativeNetRequest.updateSessionRules = () => {
            if (failure === 'throw') throw new Error('Synthetic rule error');
            if (failure === 'reject') return Promise.reject(new Error('Synthetic rule rejection'));
            return new Promise(resolve => { releaseLate = resolve; });
        };
        const reader = createBrowserReader(f.chrome, {minIntervalMs: 0, timeoutMs: 15});
        await assert.rejects(reader.readJson(FIRST), /language_rule_failed/);
        assert.deepEqual(f.navigations.map(row => row.url), ['about:blank']);
        assert.equal(f.injections.length, 0);
        if (releaseLate) {
            releaseLate();
            await new Promise(resolve => setTimeout(resolve, 5));
            assert.equal(f.navigations.length, 1, 'A late rule acknowledgement cannot start a navigation.');
        }
        f.chrome.declarativeNetRequest.updateSessionRules = async () => {};
        // Chrome may omit about:blank URL without the broad tabs permission.
        delete f.tabs.get(reader.getHelperTabId()).url;
        assert.deepEqual(await createBrowserReader(f.chrome, {minIntervalMs: 0, timeoutMs: 50}).readJson(FIRST), {raw: FIRST});
        assert.equal(f.navigations.filter(row => row.kind === 'create').length, 1);
    }
});

test('IAPI navigation removes only the exporter rule and never adds a language header', async () => {
    const f = fixture();
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0});
    await reader.readJson(FIRST);
    const before = f.ruleUpdates.length;
    const url = FEED_LINK;
    assert.deepEqual(await reader.readJson(url), {raw: url});
    const changes = f.ruleUpdates.slice(before);
    assert.ok(changes.length > 0);
    assert.ok(changes.every(change => !change.addRules?.length && change.removeRuleIds.length === 1 && change.removeRuleIds[0] === LANGUAGE_RULE_ID));
});

test('IAPI reader accepts only a valid signed stock endpoint, before any tab or rule operation', async () => {
    const f = fixture();
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0});
    for (const url of ['https://iapi.ge/admin/alta-browser/feed?include_out_of_stock=1',
        'https://iapi.ge/feeds/alta-stock.json', FEED_LINK + '&extra=1', FEED_LINK + '#fragment']) {
        await assert.rejects(reader.readJson(url), /invalid_feed_link/);
    }
    assert.equal(f.navigations.length, 0);
    assert.equal(f.ruleUpdates.length, 0);
});

async function until(predicate) {
    const deadline = Date.now() + 1000;
    while (!predicate()) {
        if (Date.now() >= deadline) throw new Error('Browser fixture did not settle');
        await new Promise(resolve => setTimeout(resolve, 2));
    }
}

test('old committed page after tabs.update is tolerated until the target URL is complete', async () => {
    const f = fixture();
    f.session[KEY] = {tabId: 7};
    f.tabs.set(7, {id: 7, url: FIRST, status: 'complete'});
    let updating = false, afterUpdateReads = 0;
    const originalGet = f.chrome.tabs.get;
    f.chrome.tabs.update = async id => { updating = true; return {id, url: FIRST, status: 'complete'}; };
    f.chrome.tabs.get = async id => {
        if (!updating) return originalGet(id);
        afterUpdateReads += 1;
        return {id, url: afterUpdateReads === 1 ? FIRST : SECOND, status: 'complete'};
    };
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0, pollMs: 1, timeoutMs: 100});
    assert.deepEqual(await reader.readJson(SECOND), {raw: SECOND});
    assert.equal(afterUpdateReads, 2);
    assert.equal(f.injections.length, 1);
    assert.equal(f.injections[0].args[0], SECOND);
});

test('never-resolving outer script injection times out and late responses cannot resume the read', async () => {
    const f = fixture();
    let resolveLate;
    f.chrome.scripting.executeScript = () => new Promise(resolve => { resolveLate = resolve; });
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0, injectionTimeoutMs: 10});
    let completed = false;
    await assert.rejects(reader.readJson(FIRST).then(value => { completed = true; return value; }), /script_timeout/);
    resolveLate([{result: {ok: true, data: {late: true}}}]);
    await new Promise(resolve => setTimeout(resolve, 5));
    assert.equal(completed, false);
});

test('a stuck navigation call is bounded and never starts script injection afterward', async () => {
    const f = fixture();
    f.session[KEY] = {tabId: 7};
    f.tabs.set(7, {id: 7, url: FIRST, status: 'complete'});
    let resolveLate;
    f.chrome.tabs.update = () => new Promise(resolve => { resolveLate = resolve; });
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0, timeoutMs: 10});
    await assert.rejects(reader.readJson(SECOND), /navigation_timeout/);
    resolveLate({id: 7, url: SECOND, status: 'complete'});
    await new Promise(resolve => setTimeout(resolve, 5));
    assert.equal(f.injections.length, 0);
});

test('loading pages and unexpected redirects are bounded or rejected before data collection', async () => {
    const f = fixture();
    const original = f.chrome.tabs.get;
    f.chrome.tabs.get = async id => ({...await original(id), status: 'loading'});
    await assert.rejects(createBrowserReader(f.chrome, {timeoutMs: 10, pollMs: 1, minIntervalMs: 0}).readJson(FIRST), /navigation_timeout/);
    const redirected = fixture();
    redirected.chrome.tabs.get = async id => ({id, url: 'https://iapi.ge/login', status: 'complete'});
    await assert.rejects(createBrowserReader(redirected.chrome, {minIntervalMs: 0}).readJson(FIRST), /wrong_page/);
});

test('unapproved origins and embedded URL credentials never create a tab', async () => {
    const f = fixture();
    const reader = createBrowserReader(f.chrome, {minIntervalMs: 0});
    for (const url of ['http://alta.ge/api', 'https://foreign.invalid/', 'https://alta.ge.evil.invalid/', 'https://user:pass@alta.ge/api']) {
        await assert.rejects(reader.readJson(url), /wrong_page/);
    }
    assert.equal(f.navigations.length, 0);
});

test('rendered JSON reader reads visible body data and distinguishes challenge, invalid JSON and wrong pages', () => {
    const previous = {document: globalThis.document, location: globalThis.location};
    globalThis.location = {href: FIRST};
    globalThis.document = {title: 'JSON', querySelector: () => ({innerText: '{"products":[{"id":741}]}'}), body: {innerText: ''}};
    try {
        assert.deepEqual(readRenderedJson(FIRST), {ok: true, data: {products: [{id: 741}]}});
        globalThis.document = {title: 'JSON', querySelector: () => null, body: {innerText: '{"items":[]}'}};
        assert.deepEqual(readRenderedJson(FIRST), {ok: true, data: {items: []}});
        globalThis.document.title = 'Just a moment...';
        assert.equal(readRenderedJson(FIRST).code, 'challenge');
        globalThis.document.title = 'JSON';
        globalThis.document.body.innerText = '<html>Login</html>';
        assert.equal(readRenderedJson(FIRST).code, 'invalid_json');
        globalThis.location.href = 'https://iapi.ge/login';
        assert.equal(readRenderedJson(FIRST).code, 'wrong_page');
    } finally {
        globalThis.document = previous.document;
        globalThis.location = previous.location;
    }
});
