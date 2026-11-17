// Photos travel inside the file because the site cannot fetch them from
// alta's image store itself. The site takes JPEG, PNG, GIF and WEBP only, at
// most 1 MB a photo and 2 MB a product, and refuses the whole product
// otherwise. alta's store answers a modern browser with AVIF, which is how
// whole products used to be refused; so the photos are asked for in the
// accepted kinds and redrawn as JPEG when they still come back otherwise or
// too big.

const PHOTO_MAX = 1_000_000;
const PRODUCT_MAX = 2_000_000;
const SIDE_MAX = 4000;
// Tried in turn on the largest photo until the product fits.
const STEPS = [[1600, 0.86], [1400, 0.8], [1200, 0.75], [1000, 0.7], [800, 0.65], [640, 0.6]];

function kind(bytes) {
    const b = bytes;

    if (b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF) return 'image/jpeg';
    if (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47) return 'image/png';
    if (b[0] === 0x47 && b[1] === 0x49 && b[2] === 0x46) return 'image/gif';
    if (b[0] === 0x52 && b[1] === 0x49 && b[2] === 0x46 && b[3] === 0x46
        && b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50) return 'image/webp';

    return null;
}

async function download(url) {
    let lastError;

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const response = await fetch(url, {
                credentials: 'omit',
                cache: 'no-store',
                headers: {Accept: 'image/webp,image/jpeg,image/png;q=0.9,image/gif;q=0.8'},
            });

            if (!response.ok) {
                throw new Error(`photo_http_${response.status}`);
            }

            return new Uint8Array(await response.arrayBuffer());
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError;
}

async function redraw(bytes, side, quality) {
    const bitmap = await createImageBitmap(new Blob([bytes]));
    const scale = Math.min(1, side / Math.max(bitmap.width, bitmap.height));
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));
    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext('2d');

    // JPEG has no transparency; a cut-out product goes on white, not black.
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const blob = await canvas.convertToBlob({type: 'image/jpeg', quality});

    return new Uint8Array(await blob.arrayBuffer());
}

async function oversized(bytes) {
    const bitmap = await createImageBitmap(new Blob([bytes]));
    const big = Math.max(bitmap.width, bitmap.height) > SIDE_MAX;
    bitmap.close();

    return big;
}

function base64(bytes) {
    let binary = '';

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

// Every one of the urls, or an error: the site wants all of them or none.
export async function collectPhotos(urls) {
    const photos = [];

    for (const url of urls) {
        const original = await download(url);
        let bytes = original;

        if (!kind(bytes) || bytes.length > PHOTO_MAX || await oversized(bytes)) {
            bytes = await redraw(original, STEPS[0][0], STEPS[0][1]);
        }

        photos.push({url, original, bytes, step: bytes === original ? -1 : 0});
    }

    const total = () => photos.reduce((sum, photo) => sum + photo.bytes.length, 0);

    while (total() > PRODUCT_MAX || photos.some(photo => photo.bytes.length > PHOTO_MAX)) {
        const largest = photos.reduce((a, b) => (b.bytes.length > a.bytes.length ? b : a));

        if (largest.step >= STEPS.length - 1) {
            throw new Error('photos_too_large');
        }

        largest.step++;
        const [side, quality] = STEPS[largest.step];
        largest.bytes = await redraw(largest.original, side, quality);
    }

    return photos.map(photo => ({url: photo.url, base64: base64(photo.bytes)}));
}
