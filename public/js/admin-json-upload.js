const ENDPOINT = '/admin/import/json-file';
const MAX_CHUNK_BYTES = 8 * 1024 * 1024;
const UUID = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i;

function uploadError(code, message = code, status = null, cause) {
    const error = new Error(message, cause ? {cause} : undefined);
    error.code = code;
    error.status = status;
    return error;
}

function checkCancelled(signal) {
    if (signal?.aborted) throw uploadError('upload_cancelled');
}

function statusCode(status) {
    if (status === 401 || status === 419) return 'session_expired';
    if (status === 403) return 'upload_forbidden';
    if (status === 413) return 'upload_too_large';
    if (status === 507) return 'upload_disk_full';
    if (status === 409) return 'upload_conflict';
    if (status === 422) return 'upload_validation_failed';
    return 'upload_server_error';
}

async function jsonResponse(response, signal) {
    checkCancelled(signal);
    const status = response?.status;
    if (!Number.isInteger(status)) throw uploadError('upload_invalid_response');
    const type = response.headers?.get?.('content-type') || '';
    const isJson = /^application\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)/i.test(type);
    let body;
    if (isJson) {
        try { body = await response.json(); }
        catch (error) {
            checkCancelled(signal);
            throw uploadError(status < 200 || status >= 300 ? statusCode(status) : 'upload_invalid_response', undefined, status, error);
        }
    }
    checkCancelled(signal);
    if (status < 200 || status >= 300) {
        const serverCode = typeof body?.error === 'string' ? body.error : body?.code;
        const fixedStatus = [401, 419, 403, 413, 507].includes(status);
        const code = !fixedStatus && typeof serverCode === 'string' && /^[a-z][a-z0-9_]{1,80}$/.test(serverCode)
            ? serverCode : statusCode(status);
        const message = typeof body?.message === 'string' && !/[<>]/.test(body.message)
            ? body.message.slice(0, 400) : code;
        throw uploadError(code, message, status);
    }
    if (!isJson || !body || typeof body !== 'object' || Array.isArray(body)) throw uploadError('upload_invalid_response', undefined, status);
    return body;
}

function validateFile(file) {
    if (!file || typeof file.name !== 'string' || typeof file.slice !== 'function') throw uploadError('upload_invalid_file');
    const name = file.name.trim();
    if (!/^.+\.json$/i.test(name) || Array.from(name).length > 255 || /[\/\\\u0000-\u001f\u007f]/.test(name)) {
        throw uploadError('upload_invalid_file');
    }
    if (!Number.isSafeInteger(file.size) || file.size <= 0) throw uploadError('upload_empty_file');
    return name;
}

export function createJsonUploader({fetchImpl = globalThis.fetch, csrfToken, onProgress = () => {}, signal, maxRetries = 2} = {}) {
    let uploading = false;

    async function request(path, body, {binary = false, offset, retry = false, networkCode = 'upload_network_error'} = {}) {
        const headers = {'Accept': 'application/json', 'Content-Type': binary ? 'application/octet-stream' : 'application/json',
            'X-CSRF-TOKEN': csrfToken};
        if (offset !== undefined) headers['X-Upload-Offset'] = String(offset);
        for (let attempt = 0; ; attempt++) {
            checkCancelled(signal);
            let response;
            try {
                response = await fetchImpl(path, {method: 'POST', credentials: 'same-origin', redirect: 'error', headers, body, signal});
            } catch (error) {
                if (signal?.aborted || error?.name === 'AbortError') throw uploadError('upload_cancelled', undefined, null, error);
                if (retry && attempt < maxRetries) continue;
                throw uploadError(networkCode, undefined, null, error);
            }
            // Response/validation failures are deliberately not network retries.
            return jsonResponse(response, signal);
        }
    }

    return {async upload(file, supplier) {
        if (uploading) throw uploadError('upload_already_running');
        const name = validateFile(file);
        if (typeof supplier !== 'string' || !/^[a-z0-9_-]{1,64}$/i.test(supplier)) throw uploadError('upload_invalid_supplier');
        if (typeof csrfToken !== 'string' || !csrfToken.trim() || /[\r\n]/.test(csrfToken)) throw uploadError('csrf_missing');
        if (!Number.isSafeInteger(maxRetries) || maxRetries < 0 || maxRetries > 5) throw uploadError('upload_invalid_retry_limit');
        checkCancelled(signal);
        uploading = true;
        try {
            onProgress({uploaded: 0, total: file.size});
            // Begin is not retried: a lost response may already have created a private upload.
            const begin = await request(`${ENDPOINT}/begin`, JSON.stringify({name, size: file.size, supplier}),
                {networkCode: 'upload_begin_uncertain'});
            if (typeof begin.id !== 'string' || !UUID.test(begin.id) || !Number.isSafeInteger(begin.chunk_size)
                || begin.chunk_size <= 0 || begin.chunk_size > MAX_CHUNK_BYTES || begin.received !== 0) {
                throw uploadError('upload_invalid_response');
            }
            const id = begin.id;
            let offset = 0;
            while (offset < file.size) {
                checkCancelled(signal);
                const end = Math.min(offset + begin.chunk_size, file.size);
                const chunk = file.slice(offset, end);
                if (!chunk || chunk.size !== end - offset) throw uploadError('upload_invalid_file');
                // Keep this exact slice and offset for every network retry of this request.
                const appended = await request(`${ENDPOINT}/${id}/chunk`, chunk, {binary: true, offset, retry: true});
                if (!Number.isSafeInteger(appended.received) || appended.received !== end || appended.total !== file.size) {
                    throw uploadError('upload_offset_mismatch');
                }
                offset = appended.received;
                onProgress({uploaded: offset, total: file.size});
            }
            checkCancelled(signal);
            const result = await request(`${ENDPOINT}/${id}/finish`, '{}', {retry: true, networkCode: 'upload_finish_uncertain'});
            if (result.id !== id || result.name !== name || result.size !== file.size || typeof result.sha256 !== 'string'
                || !/^[0-9a-f]{64}$/i.test(result.sha256)) throw uploadError('upload_invalid_response');
            checkCancelled(signal);
            return result;
        } finally { uploading = false; }
    }};
}

if (typeof window !== 'undefined') window.createJsonUploader = createJsonUploader;
