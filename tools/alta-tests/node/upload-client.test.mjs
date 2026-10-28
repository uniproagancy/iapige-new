import test from 'node:test';
import assert from 'node:assert/strict';
import {createJsonUploader} from '../../../public/js/admin-json-upload.js';

const MiB = 1024 * 1024;
const ID = '11111111-1111-4111-8111-111111111111';
const SHA = 'a'.repeat(64);
const csrfToken = 'synthetic-csrf-token';
const json = (value, status = 200) => new Response(JSON.stringify(value), {status, headers: {'Content-Type': 'application/json'}});
const hasCode = code => error => error.code === code;

function fileFixture(size = 9 * MiB + 11, name = 'alta.json') {
    const slices = [];
    return {name, size, slices, text() { throw new Error('Whole-file text must never be read'); },
        arrayBuffer() { throw new Error('Whole-file bytes must never be read'); },
        slice(start, end) { const chunk = {size: end - start, start, end}; slices.push(chunk); return chunk; },
    };
}

function server(file, {before, chunkSize = 4 * MiB} = {}) {
    const calls = [], accepted = new Map();
    let received = 0;
    const metadata = {id: ID, name: file.name.trim(), size: file.size, sha256: SHA};
    function acceptChunk(options) {
        const offset = Number(options.headers['X-Upload-Offset']);
        assert.equal(options.headers['Content-Type'], 'application/octet-stream');
        assert.equal(options.body.start, offset);
        if (accepted.has(offset)) assert.equal(accepted.get(offset), options.body);
        else { assert.equal(offset, received); accepted.set(offset, options.body); received = options.body.end; }
        return json({received: options.body.end, total: file.size});
    }
    return {calls, accepted, metadata, acceptChunk, async fetchImpl(url, options) {
        calls.push({url, options});
        const overridden = await before?.(url, options, calls);
        if (overridden !== undefined) return overridden;
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers['X-CSRF-TOKEN'], csrfToken);
        assert.equal(options.headers.Accept, 'application/json');
        assert.ok(url.startsWith('/admin/import/json-file/'));
        assert.ok(!url.startsWith('//') && !url.includes('://'));
        if (url.endsWith('/begin')) {
            assert.deepEqual(JSON.parse(options.body), {name: file.name.trim(), size: file.size, supplier: 'alta'});
            return json({id: ID, chunk_size: chunkSize, received: 0});
        }
        if (url.endsWith('/chunk')) {
            return acceptChunk(options);
        }
        assert.equal(url, `/admin/import/json-file/${ID}/finish`);
        assert.equal(received, file.size);
        assert.equal(options.body, '{}');
        return json(metadata);
    }};
}

test('a file above64MiB is sliced and fully uploaded with byte-accurate progress without reading whole-file text', async () => {
    const file = fileFixture(65 * MiB + 123);
    const remote = server(file), progress = [];
    const metadata = await createJsonUploader({fetchImpl: remote.fetchImpl, csrfToken, onProgress: value => progress.push(value)}).upload(file, 'alta');
    assert.deepEqual(metadata, remote.metadata);
    assert.equal(file.slices.length, 17);
    assert.equal(file.slices.reduce((sum, chunk) => sum + chunk.size, 0), file.size);
    assert.ok(file.slices.every(chunk => chunk.size <= 4 * MiB));
    assert.deepEqual(progress[0], {uploaded: 0, total: file.size});
    assert.deepEqual(progress.at(-1), {uploaded: file.size, total: file.size});
    assert.ok(progress.every((row, index) => index === 0 || row.uploaded > progress[index - 1].uploaded));
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 1);
});

test('a lost chunk response retries the exact same offset and Blob slice without skipping or duplicating bytes', async () => {
    const file = fileFixture();
    let lost = false, duplicateBody, duplicateOffset;
    const remote = server(file, {before(url, options) {
        if (url.endsWith('/chunk') && options.headers['X-Upload-Offset'] === String(4 * MiB)) {
            if (!lost) {
                lost = true; duplicateBody = options.body; duplicateOffset = options.headers['X-Upload-Offset'];
                remote.acceptChunk(options);
                throw new TypeError('Synthetic response lost after processing');
            }
            assert.equal(options.body, duplicateBody);
            assert.equal(options.headers['X-Upload-Offset'], duplicateOffset);
        }
    }});
    await createJsonUploader({fetchImpl: remote.fetchImpl, csrfToken}).upload(file, 'alta');
    assert.equal(file.slices.length, 3, 'A retry reuses the existing slice rather than slicing the file again.');
    assert.deepEqual(remote.calls.filter(call => call.url.endsWith('/chunk')).map(call => call.options.headers['X-Upload-Offset']),
        ['0', String(4 * MiB), String(4 * MiB), String(8 * MiB)]);
    assert.equal(remote.accepted.size, 3);
});

test('begin is never retried after an unknown network result; exhausted chunk retries are bounded', async () => {
    let calls = 0;
    await assert.rejects(createJsonUploader({csrfToken, fetchImpl: async () => { calls++; throw new TypeError('Offline'); }})
        .upload(fileFixture(), 'alta'), hasCode('upload_begin_uncertain'));
    assert.equal(calls, 1);
    const file = fileFixture(), remote = server(file, {before(url) { if (url.endsWith('/chunk')) throw new TypeError('Offline'); }});
    await assert.rejects(createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta'), hasCode('upload_network_error'));
    assert.equal(remote.calls.filter(call => call.url.endsWith('/chunk')).length, 3);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 0);
});

test('finish may retry its idempotent request without reuploading file chunks', async () => {
    const file = fileFixture(11);
    let failures = 0;
    const remote = server(file, {before(url) { if (url.endsWith('/finish') && failures++ === 0) throw new TypeError('Lost finish response'); }});
    await createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta');
    assert.equal(remote.calls.filter(call => call.url.endsWith('/chunk')).length, 1);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 2);
    assert.ok(remote.calls.filter(call => call.url.endsWith('/finish')).every(call => call.options.body === '{}'));
});

test('Cancel before upload or after an acknowledged chunk prevents any finish request', async () => {
    const before = new AbortController(); before.abort();
    let calls = 0;
    await assert.rejects(createJsonUploader({csrfToken, signal: before.signal, fetchImpl: async () => { calls++; }})
        .upload(fileFixture(), 'alta'), hasCode('upload_cancelled'));
    assert.equal(calls, 0);
    const file = fileFixture(), remote = server(file), controller = new AbortController();
    await assert.rejects(createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl, signal: controller.signal,
        onProgress({uploaded}) { if (uploaded) controller.abort(); }}).upload(file, 'alta'), hasCode('upload_cancelled'));
    assert.equal(remote.calls.filter(call => call.url.endsWith('/chunk')).length, 1);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 0);
});

test('Cancel during a pending chunk cannot advance to the next chunk even if a late response arrives', async () => {
    const file = fileFixture(), controller = new AbortController();
    let release;
    const remote = server(file, {before(url, options) {
        if (url.endsWith('/chunk')) return new Promise(resolve => { release = () => resolve(json({received: options.body.end, total: file.size})); });
    }});
    const pending = createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl, signal: controller.signal}).upload(file, 'alta');
    const rejected = assert.rejects(pending, hasCode('upload_cancelled'));
    while (!release) await Promise.resolve();
    controller.abort(); release(); await rejected;
    assert.equal(remote.calls.filter(call => call.url.endsWith('/chunk')).length, 1);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 0);
});

test('413, expired CSRF/session, disk full and non-JSON responses yield friendly codes without retrying HTML', async () => {
    const cases = [[413, 'text/html', '<html>Too large</html>', 'upload_too_large'],
        [419, 'text/html', '<html>Page expired</html>', 'session_expired'], [401, 'text/html', '<html>Login</html>', 'session_expired'],
        [507, 'application/json', '{"message":"Disk full"}', 'upload_disk_full'],
        [200, 'text/html', '<html>Unexpected login</html>', 'upload_invalid_response'],
        [200, 'application/json', '{bad-json', 'upload_invalid_response']];
    for (const [status, type, body, code] of cases) {
        let calls = 0;
        await assert.rejects(createJsonUploader({csrfToken, fetchImpl: async () => { calls++;
            return new Response(body, {status, headers: {'Content-Type': type}}); }}).upload(fileFixture(), 'alta'), error => {
            assert.equal(error.code, code);
            assert.ok(!error.message.includes('<html>'));
            return true;
        });
        assert.equal(calls, 1);
    }
});

test('a rejected chunk preserves the server error enum and is never treated as a retryable network error', async () => {
    const file = fileFixture(), remote = server(file, {before(url) {
        if (url.endsWith('/chunk')) return json({error: 'upload_expired', message: 'Private upload expired'}, 409);
    }});
    await assert.rejects(createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta'), error =>
        error.code === 'upload_expired' && error.message === 'Private upload expired' && error.status === 409);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/chunk')).length, 1);
    assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 0);
});

test('server-provided foreign IDs or URL fields cannot redirect uploads to another origin', async () => {
    const file = fileFixture(1), requests = [];
    await assert.rejects(createJsonUploader({csrfToken, baseUrl: 'https://foreign.invalid', fetchImpl: async url => {
        requests.push(url);
        return json({id: 'https://foreign.invalid/upload', chunk_size: 1, received: 0});
    }}).upload(file, 'alta'), hasCode('upload_invalid_response'));
    assert.deepEqual(requests, ['/admin/import/json-file/begin']);
    const remote = server(file, {before(url) {
        if (url.endsWith('/begin')) return json({id: ID, chunk_size: 4 * MiB, received: 0,
            chunk_url: 'https://foreign.invalid/upload', finish_url: '//foreign.invalid/finish'});
    }});
    await createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta');
    assert.ok(remote.calls.every(call => call.url.startsWith('/admin/import/json-file/')));
});

test('invalid server chunk sizes and offsets stop before slicing or silently skipping any bytes', async () => {
    for (const size of [0, -1, 8 * MiB + 1, '4194304', 1.5]) {
        const file = fileFixture();
        await assert.rejects(createJsonUploader({csrfToken, fetchImpl: async () => json({id: ID, chunk_size: size, received: 0})})
            .upload(file, 'alta'), hasCode('upload_invalid_response'));
        assert.equal(file.slices.length, 0);
    }
    for (const appended of [{received: 0, total: 9 * MiB + 11}, {received: 4 * MiB, total: 1}]) {
        const file = fileFixture(), remote = server(file, {before(url) { if (url.endsWith('/chunk')) return json(appended); }});
        await assert.rejects(createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta'), hasCode('upload_offset_mismatch'));
        assert.equal(remote.calls.filter(call => call.url.endsWith('/finish')).length, 0);
    }
});

test('empty/unsafe files, invalid supplier and missing CSRF are rejected before any request', async () => {
    const cases = [[fileFixture(0), 'alta', csrfToken, 'upload_empty_file'],
        [fileFixture(1, 'alta.txt'), 'alta', csrfToken, 'upload_invalid_file'],
        [fileFixture(1, '../alta.json'), 'alta', csrfToken, 'upload_invalid_file'],
        [fileFixture(1, `${'a'.repeat(256)}.json`), 'alta', csrfToken, 'upload_invalid_file'],
        [fileFixture(1), 'https://foreign.invalid', csrfToken, 'upload_invalid_supplier'],
        [fileFixture(1), 'alta', '', 'csrf_missing']];
    for (const [file, supplier, token, code] of cases) {
        let calls = 0;
        await assert.rejects(createJsonUploader({csrfToken: token, fetchImpl: async () => { calls++; }}).upload(file, supplier), hasCode(code));
        assert.equal(calls, 0);
    }
});

test('finish metadata must identify the same file and checksum before the UI can adopt its source ID', async () => {
    for (const changed of [{id: '22222222-2222-4222-8222-222222222222'}, {size: 2}, {sha256: 'not-a-hash'}, {name: 'other.json'}]) {
        const file = fileFixture(1), remote = server(file, {before(url) {
            if (url.endsWith('/finish')) return json({id: ID, name: file.name, size: file.size, sha256: SHA, ...changed});
        }});
        await assert.rejects(createJsonUploader({csrfToken, fetchImpl: remote.fetchImpl}).upload(file, 'alta'), hasCode('upload_invalid_response'));
    }
});
