export const MAX_IMAGE_BYTES = 1024 * 1024;
export const MAX_PRODUCT_IMAGE_BYTES = 2 * 1024 * 1024;
export const MAX_IMAGE_SIDE = 12_000;
export const MAX_IMAGE_PIXELS = 40_000_000;
export const PHOTO_ORIGINS = ['https://imgstore.alta.ge/*'];
const TYPES = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

export function validateImageUrl(value) {
    if (typeof value !== 'string') throw new Error('photo_url_invalid');
    const text = value.trim();
    // Inspect the original path before URL normalization can remove dot segments.
    const match = /^https:\/\/(imgstore\.alta\.ge|alta\.ge)(\/images\/[^?#]+)$/.exec(text);
    if (!match || /[\\\s\u0000-\u001f\u007f]/.test(text)) throw new Error('photo_url_invalid');
    let path = match[2];
    try {
        for (let i = 0; i < 4; i++) {
            const decoded = decodeURIComponent(path);
            if (decoded === path) break;
            path = decoded;
        }
    } catch { throw new Error('photo_url_invalid'); }
    if (path.includes('%') || /[\\?#\u0000-\u001f\u007f]/.test(path)
        || path.split('/').some(part => part === '.' || part === '..')
        || !/\.(?:jpe?g|png|gif|webp)$/i.test(path)) throw new Error('photo_url_invalid');
    const url = new URL(text);
    if (url.username || url.password || url.port || url.search || url.hash) throw new Error('photo_url_invalid');
    return text;
}

export function imageUrls(raw) {
    const product = raw?.product;
    if (!product || typeof product !== 'object') throw new Error('invalid_details');
    const supplied = [product.imageUrl, ...(Array.isArray(product.images) ? product.images : [])];
    const urls = [];
    for (const candidate of supplied) {
        if (candidate === undefined || candidate === null || candidate === '') continue;
        if (typeof candidate !== 'string') throw new Error('photo_url_invalid');
        const url = candidate.trim();
        if (url && !urls.includes(url)) urls.push(url);
    }
    return urls.slice(0, 8).map(validateImageUrl);
}

function base64(bytes) {
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 16_384) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 16_384));
    }
    return btoa(binary);
}

export function completeAssets(raw, assets) {
    const urls = imageUrls(raw);
    return Array.isArray(assets) && assets.length === urls.length
        && assets.every((asset, index) => asset?.url === urls[index]
            && typeof asset.base64 === 'string' && asset.base64.length > 0);
}

export function createPhotoCollector({fetchImpl = globalThis.fetch, decode = blob => createImageBitmap(blob),
    timeoutMs = 15_000} = {}) {
    async function collectOne(url, remaining, signal) {
        validateImageUrl(url);
        const controller = new AbortController();
        let expired = false, reader;
        let timer, abortListener;
        const cancelled = new Promise((_, reject) => {
            abortListener = () => {
                controller.abort();
                void reader?.cancel().catch(() => {});
                reject(new Error('photo_cancelled'));
            };
            signal?.addEventListener('abort', abortListener, {once: true});
            if (signal?.aborted) abortListener();
        });
        const deadline = new Promise((_, reject) => {
            timer = setTimeout(() => {
                expired = true;
                controller.abort();
                void reader?.cancel().catch(() => {});
                reject(new Error('photo_timeout'));
            }, timeoutMs);
        });
        const operation = (async () => {
            let response;
            try { response = await fetchImpl(url, {credentials: 'omit', redirect: 'error', signal: controller.signal}); }
            catch { throw new Error(expired ? 'photo_timeout' : 'photo_request_failed'); }
            if (signal?.aborted) throw new Error('photo_cancelled');
            if (expired) throw new Error('photo_timeout');
            if (response.status === 403) throw new Error('photo_forbidden');
            if (response.status !== 200 || response.redirected) throw new Error('photo_request_failed');
            if (response.url && response.url !== url) throw new Error('photo_request_failed');
            const type = (response.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
            if (!TYPES.has(type)) throw new Error('photo_invalid_type');
            const declared = Number(response.headers.get('content-length'));
            if (Number.isFinite(declared) && declared > MAX_IMAGE_BYTES) throw new Error('photo_too_large');
            if (Number.isFinite(declared) && declared > remaining) throw new Error('photo_total_too_large');
            if (!response.body?.getReader) throw new Error('photo_invalid_image');
            reader = response.body.getReader();
            const chunks = [];
            let length = 0;
            try {
                for (;;) {
                    const {done, value} = await reader.read();
                    if (signal?.aborted) throw new Error('photo_cancelled');
                    if (expired) throw new Error('photo_timeout');
                    if (done) break;
                    length += value.byteLength;
                    if (length > MAX_IMAGE_BYTES) throw new Error('photo_too_large');
                    if (length > remaining) throw new Error('photo_total_too_large');
                    chunks.push(value);
                }
            } catch (error) {
                void reader.cancel().catch(() => {});
                throw error;
            }
            if (!length) throw new Error('photo_invalid_image');
            const bytes = new Uint8Array(length);
            let offset = 0;
            for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
            let bitmap;
            try { bitmap = await decode(new Blob([bytes], {type})); }
            catch { throw new Error('photo_invalid_image'); }
            try {
                if (signal?.aborted) throw new Error('photo_cancelled');
                if (expired) throw new Error('photo_timeout');
                if (!Number.isSafeInteger(bitmap?.width) || !Number.isSafeInteger(bitmap?.height)
                    || bitmap.width <= 0 || bitmap.height <= 0) throw new Error('photo_invalid_image');
                if (bitmap.width > MAX_IMAGE_SIDE || bitmap.height > MAX_IMAGE_SIDE
                    || bitmap.width * bitmap.height > MAX_IMAGE_PIXELS) throw new Error('photo_dimensions');
            } finally { bitmap?.close?.(); }
            return {asset: {url, base64: base64(bytes)}, size: length};
        })();
        try { return await Promise.race([operation, deadline, cancelled]); }
        finally {
            clearTimeout(timer);
            signal?.removeEventListener('abort', abortListener);
        }
    }
    return {async collect(raw, {signal} = {}) {
        const assets = [];
        let total = 0;
        for (const url of imageUrls(raw)) {
            if (signal?.aborted) throw new Error('photo_cancelled');
            const result = await collectOne(url, MAX_PRODUCT_IMAGE_BYTES - total, signal);
            total += result.size;
            assets.push(result.asset);
        }
        return assets;
    }};
}
