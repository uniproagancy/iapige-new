import test from 'node:test';
import assert from 'node:assert/strict';
import {validateImageUrl, imageUrls, completeAssets, createPhotoCollector, MAX_IMAGE_BYTES, MAX_PRODUCT_IMAGE_BYTES} from '../photos.js';

const url = 'https://imgstore.alta.ge/images/a_Thumb.png';
const raw = (...urls) => ({product: {imageUrl: urls[0], images: urls.slice(1)}});
const png = Uint8Array.from(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=', 'base64'));
const response = (bytes = png, type = 'image/png', status = 200) => new Response(bytes, {status, headers: {'content-type': type}});
const decoder = async blob => { assert.ok(blob.size > 0); return {width: 1, height: 1, close() {}}; };

test('only exact public Alta raster paths are allowed, including rejecting normalized traversal and explicit ports', () => {
    assert.equal(validateImageUrl(url), url);
    assert.equal(validateImageUrl('https://alta.ge/images/photo.jpeg'), 'https://alta.ge/images/photo.jpeg');
    for (const invalid of ['http://imgstore.alta.ge/images/a.png', 'https://imgstore.alta.ge.evil/images/a.png',
        'HTTPS://imgstore.alta.ge/images/a.png', 'https://IMGSTORE.ALTA.GE/images/a.png', 'https://imgstore.alta.ge/Images/a.png',
        'https://user@imgstore.alta.ge/images/a.png', 'https://imgstore.alta.ge:443/images/a.png',
        `${url}?proxy=1`, `${url}#x`, 'https://imgstore.alta.ge/other/a.png',
        'https://imgstore.alta.ge/images/../a.png', 'https://imgstore.alta.ge/images/%2e%2e/a.png',
        'https://imgstore.alta.ge/images/%252e%252e/a.png', 'https://imgstore.alta.ge/images/a.svg',
        'https://imgstore.alta.ge/images/a.png?']) {
        assert.throws(() => validateImageUrl(invalid), /photo_url_invalid/, invalid);
    }
});

test('the main photo comes first, exact duplicates are removed, and only the first eight are collected', () => {
    const urls = Array.from({length: 12}, (_, i) => `https://imgstore.alta.ge/images/${i}.webp`);
    assert.deepEqual(imageUrls(raw(urls[0], urls[0], ...urls.slice(1))), urls.slice(0, 8));
    assert.deepEqual(imageUrls({product: {}}), []);
});

test('normal photo GET omits credentials, forbids redirect and adds no request headers', async () => {
    let calls = 0, closed = 0;
    const collector = createPhotoCollector({fetchImpl: async (actual, options) => {
        calls++;
        assert.equal(actual, url);
        assert.equal(options.credentials, 'omit');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers, undefined);
        assert.ok(options.signal instanceof AbortSignal);
        return response();
    }, decode: async blob => {
        assert.deepEqual(new Uint8Array(await blob.arrayBuffer()), png);
        return {width: 1200, height: 1200, close() { closed++; }};
    }});
    const assets = await collector.collect(raw(url));
    assert.equal(calls, 1);
    assert.equal(closed, 1);
    assert.deepEqual(assets, [{url, base64: Buffer.from(png).toString('base64')}]);
    assert.ok(completeAssets(raw(url), assets));
    assert.equal(completeAssets(raw(url), []), false);
});

test('403, other failures, redirects and HTML all stop once with clear error codes', async () => {
    const cases = [
        [() => response('Forbidden', 'text/html', 403), 'photo_forbidden'],
        [() => response('Unavailable', 'text/html', 503), 'photo_request_failed'],
        [() => response('<html>Challenge</html>', 'text/html'), 'photo_invalid_type'],
        [() => { throw new TypeError('Failed to fetch'); }, 'photo_request_failed'],
        [() => ({status: 200, redirected: true}), 'photo_request_failed'],
    ];
    for (const [makeResponse, error] of cases) {
        let calls = 0;
        const collector = createPhotoCollector({fetchImpl: async () => { calls++; return makeResponse(); }, decode: decoder});
        await assert.rejects(collector.collect(raw(url)), new RegExp(error));
        assert.equal(calls, 1);
    }
});

test('declared and streamed size limits reject before retaining an oversized image', async () => {
    let decoded = false, cancelled = false, reads = 0;
    const declared = createPhotoCollector({fetchImpl: async () => new Response(png, {headers: {
        'content-type': 'image/png', 'content-length': String(MAX_IMAGE_BYTES + 1)}}),
    decode: async () => { decoded = true; return decoder(new Blob([png])); }});
    await assert.rejects(declared.collect(raw(url)), /photo_too_large/);
    const streamed = createPhotoCollector({fetchImpl: async () => ({status: 200, headers: new Headers({'content-type': 'image/png'}),
        body: {getReader: () => ({async read() { reads++; return {done: false, value: new Uint8Array(MAX_IMAGE_BYTES / 2 + 1)}; },
            async cancel() { cancelled = true; }})}}), decode: async () => { decoded = true; }});
    await assert.rejects(streamed.collect(raw(url)), /photo_too_large/);
    assert.equal(reads, 2);
    assert.equal(cancelled, true);
    assert.equal(decoded, false);
});

test('the total limit counts decoded bytes rather than base64 and stops the next photo', async () => {
    const urls = [url, 'https://imgstore.alta.ge/images/b.png', 'https://imgstore.alta.ge/images/c.png'];
    const collector = createPhotoCollector({fetchImpl: async () => response(new Uint8Array(800_000)), decode: decoder});
    await assert.rejects(collector.collect(raw(...urls)), /photo_total_too_large/);
    const exact = createPhotoCollector({fetchImpl: async () => response(new Uint8Array(MAX_IMAGE_BYTES)), decode: decoder});
    const assets = await exact.collect(raw(...urls.slice(0, 2)));
    assert.equal(assets.reduce((sum, asset) => sum + Buffer.from(asset.base64, 'base64').length, 0), MAX_PRODUCT_IMAGE_BYTES);
});

test('raster decoding failures and excessive dimensions are rejected and decoded resources close', async () => {
    const broken = createPhotoCollector({fetchImpl: async () => response(), decode: async () => { throw new Error('decode failed'); }});
    await assert.rejects(broken.collect(raw(url)), /photo_invalid_image/);
    for (const [width, height] of [[12_001, 1], [1, 12_001], [7000, 6000], [0, 1]]) {
        let closed = false;
        const collector = createPhotoCollector({fetchImpl: async () => response(), decode: async () => ({width, height, close() { closed = true; }})});
        await assert.rejects(collector.collect(raw(url)), /photo_dimensions|photo_invalid_image/);
        assert.equal(closed, true);
    }
});

test('a hung fetch, body read or decoder is bounded without automatic retries', async () => {
    for (const stage of ['fetch', 'body', 'decode']) {
        let calls = 0, signal;
        const never = () => new Promise(() => {});
        const collector = createPhotoCollector({timeoutMs: 10, fetchImpl: async (_, options) => {
            calls++; signal = options.signal;
            if (stage === 'fetch') return never();
            if (stage === 'body') return {status: 200, headers: new Headers({'content-type': 'image/png'}),
                body: {getReader: () => ({read: never, cancel: async () => {}})}};
            return response();
        }, decode: stage === 'decode' ? never : decoder});
        await assert.rejects(collector.collect(raw(url)), /photo_timeout/);
        assert.equal(calls, 1);
        assert.equal(signal.aborted, true);
    }
});

test('Pause aborts the active normal fetch and cannot start the next product photo', async () => {
    const controller = new AbortController();
    let calls = 0, requestSignal;
    const collector = createPhotoCollector({fetchImpl: async (_, options) => {
        calls++;
        requestSignal = options.signal;
        return new Promise(() => {});
    }, decode: decoder});
    const promise = collector.collect(raw(url, 'https://imgstore.alta.ge/images/b.png'), {signal: controller.signal});
    controller.abort();
    await assert.rejects(promise, /photo_cancelled/);
    assert.equal(calls, 1);
    assert.equal(requestSignal.aborted, true);
});
