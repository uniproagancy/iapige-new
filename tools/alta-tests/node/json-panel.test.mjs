import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const blade = fs.readFileSync(new URL('../../../resources/views/livewire/admin/import/json.blade.php', import.meta.url), 'utf8');
const attribute = blade.match(/^<div x-data="([\s\S]*?)">\s*<div class="row">/)[1];
const expression = attribute.replace(/@js\(\$uploadErrors\)/g, '{upload_cancelled: "admin.json_bulk_paused"}')
    .replace(/@js\(asset\([^)]*\)\s*\.\s*[^)]*\)/g, '"/js/admin-json-upload.js?v=fixture"')
    .replace(/@js\(__\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key))
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
    .replace(/\bimport\([^)]*\)/, '__loadUploader()');

function panel(wire, module = {}) {
    const value = vm.runInNewContext(`(${expression})`, {$wire: wire, AbortController,
        document: {querySelector: () => ({content: 'synthetic-token'})}, __loadUploader: async () => module});
    value.$refs = {sourceFile: {value: 'selected.json'}};
    return value;
}

test('actual Alpine expression parses and large files use the native chunk helper instead of wire:model upload', () => {
    assert.equal(typeof panel({}).run, 'function');
    const input = blade.match(/<input id="j-file"[\s\S]*?>/)[0];
    assert.ok(!input.includes('wire:model'));
    assert.match(input, /choose\(\$event\.target\.files\[0\]\)/);
});

test('preview requests stay sequential; Pause waits for its current request and Resume continues the saved cursor', async () => {
    let release, inFlight = 0, peak = 0;
    const calls = [];
    const wire = {sourceId: 'private-source', previewCursor: 0, previewComplete: false,
        async look() {
            calls.push('look'); peak = Math.max(peak, ++inFlight);
            await new Promise(resolve => { release = resolve; });
            inFlight--; this.previewCursor = 10;
            return {done: false, processed: 10};
        },
        async previewNext() {
            calls.push('previewNext'); peak = Math.max(peak, ++inFlight);
            await Promise.resolve(); inFlight--; this.previewCursor = 14; this.previewComplete = true;
            return {done: true, processed: 14};
        },
    };
    const value = panel(wire), first = value.run('look');
    assert.equal(value.busy, true);
    await value.run('look');
    assert.deepEqual(calls, ['look'], 'Another click cannot overlap a running scan.');
    value.halt(); release(); await first;
    assert.deepEqual(calls, ['look']);
    assert.equal(value.message, 'admin.json_bulk_paused');
    assert.equal(value.busy, false);
    await value.run('look');
    assert.deepEqual(calls, ['look', 'previewNext']);
    assert.equal(peak, 1);
    assert.equal(value.message, 'admin.json_bulk_finished');
});

test('save resumes with saveNext after Pause rather than restarting save and its cursor', async () => {
    let release;
    const calls = [];
    const wire = {sourceId: 'private-source', saveStarted: false,
        async save() {
            calls.push('save'); await new Promise(resolve => { release = resolve; });
            this.saveStarted = true; return {done: false, processed: 3};
        }, async saveNext() { calls.push('saveNext'); this.saveStarted = false; return {done: true, processed: 5}; },
    };
    const value = panel(wire), first = value.run('save');
    value.halt(); release(); await first;
    await value.run('save');
    assert.deepEqual(calls, ['save', 'saveNext']);
    assert.equal(value.message, 'admin.json_bulk_finished');
});

test('handled Livewire validation returns stop immediately and non-advancing batches never create a request loop', async () => {
    for (const response of [undefined, null, {}, {done: false, processed: '10'}]) {
        let calls = 0;
        const value = panel({async look() { calls++; return response; }, async previewNext() { calls++; throw new Error('Unexpected next request'); }});
        await value.run('look');
        assert.equal(calls, 1);
        assert.equal(value.message, 'admin.json_bulk_check_errors');
        assert.equal(value.busy, false);
    }
    let calls = 0;
    const value = panel({async look() { calls++; return {done: false, processed: 10}; },
        async previewNext() { calls++; return {done: false, processed: 10}; }});
    await value.run('look');
    assert.equal(calls, 2);
    assert.equal(value.message, 'admin.json_bulk_check_errors');
});

test('choosing a large file clears the old source, uploads privately, then adopts only the finished source ID', async () => {
    const calls = [], file = {name: 'large.json', size: 90 * 1024 * 1024};
    const wire = {code: 'alta', sourceId: null, async clearUpload() { calls.push('clear'); },
        async useSource(id) { calls.push(`adopt:${id}`); this.sourceId = id; return {id, ready: true}; }};
    const value = panel(wire, {createJsonUploader(options) {
        assert.equal(options.csrfToken, 'synthetic-token');
        assert.ok(options.signal instanceof AbortSignal);
        return {async upload(actual, supplier) {
            assert.equal(actual, file); assert.equal(supplier, 'alta'); calls.push('upload');
            options.onProgress({uploaded: file.size, total: file.size});
            return {id: 'finished-private-source'};
        }};
    }});
    await value.choose(file);
    assert.deepEqual(calls, ['clear', 'upload', 'adopt:finished-private-source']);
    assert.equal(value.uploaded, file.size);
    assert.equal(value.message, 'admin.json_upload_ready');
    assert.equal(value.$refs.sourceFile.value, '');
    assert.equal(value.busy, false);
});
