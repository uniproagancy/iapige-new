<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\ImageDeduper;
use App\Services\Images\Thumbnailer;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaImageCache;
use App\Support\Redact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageDownloader
{
    protected const DISK = 'public';

    public const MAX_IMAGES = 8;

    protected const CONNECT_TIMEOUT = 10;

    protected const READ_TIMEOUT = 10;

    protected const RETRIES = 1;

    protected function budget(): int
    {
        return (int) config('shop.import_image_budget', 120);
    }

    protected const FAILURES_BEFORE_SKIP = 5;

    protected static array $failures = [];

    public function sync(Product $product, array $urls, int $limit = self::MAX_IMAGES): void
    {
        $deadline = microtime(true) + $this->budget();

        /* The same address listed twice would be fetched twice and stored
           under two names, because the index is part of the filename. */
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

        foreach (array_slice($urls, 0, $limit) as $i => $url) {

            if (microtime(true) >= $deadline) {
                Log::channel('import')->info('image budget spent', [
                    'product' => $product->id,
                    'fetched' => $i,
                    'of' => min(count($urls), $limit),
                ]);

                return;
            }

            try {
                $path = $this->store($product, $url, $i);
            } catch (\Throwable $e) {

                Log::channel('import')->warning('image skipped', [
                    'product' => $product->id,
                    'url' => Redact::secrets($url),
                    'error' => Redact::secrets($e->getMessage()),
                ]);

                continue;
            }

            if ($path) {
                $image = ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'sort_order' => $i],
                    ['path' => $path, 'has_thumbs' => false],
                );

                app(Thumbnailer::class)->make($image, force: true);
            }
        }

        /* Two addresses can still hand back the same picture. Tidying them is
           worth doing, but never at the cost of the product itself. */
        try {
            app(ImageDeduper::class)->prune($product->fresh());
        } catch (\Throwable $e) {
            Log::channel('import')->warning('image dedupe skipped', [
                'product' => $product->id,
                'error' => Redact::secrets($e->getMessage()),
            ]);
        }
    }

    protected function store(Product $product, string $url, int $index): ?string
    {
        $cached = null;
        $altaImage = false;

        if (in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['imgstore.alta.ge', 'alta.ge'], true)) {
            $product->loadMissing('offers.supplier');

            foreach ($product->offers as $offer) {
                if ($offer->supplier?->driver !== AltaDriver::class) {
                    continue;
                }

                $altaImage = true;
                $config = array_replace((array) config('services.alta', []), $offer->supplier->config ?? []);
                $directory = $config['retail_directory'] ?? storage_path('app/private/alta-retail');

                if ($directory) {
                    $cached = AltaImageCache::read($directory, $url, (int) ($config['retail_max_age'] ?? 86400));
                }

                break;
            }
        }

        $path = sprintf(
            'products/%d/%s-%02d-%s.%s',
            $product->id,
            Str::slug(Str::limit($product->sku, 40, '')) ?: 'p'.$product->id,
            $index + 1,
            substr(sha1($url.($cached['hash'] ?? '')), 0, 12),
            $cached['extension'] ?? $this->extension($url),
        );

        if (Storage::disk(self::DISK)->exists($path)) {
            if ($altaImage && $this->altaRasterExtension(Storage::disk(self::DISK)->get($path)) === null) {
                return null;
            }

            return $path;
        }

        if ($cached !== null) {
            if ($this->altaRasterExtension($cached['bytes']) === null) {
                return null;
            }

            return Storage::disk(self::DISK)->put($path, $cached['bytes']) ? $path : null;
        }

        if ($this->hostIsDown($url)) {
            return null;
        }

        try {
            $response = Http::timeout(self::READ_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->retry(self::RETRIES, 500, throw: false)
                ->withOptions([

                    'force_ip_resolve' => 'v4',
                ])
                ->withHeaders([
                    'User-Agent' => config('services.import.user_agent', 'Mozilla/5.0'),

                    'Referer' => parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/',
                ])
                ->get($this->addressOf($url));

            if (! $response->successful()) {
                $this->noteFailure($url);

                Log::channel('import')->warning('image download failed', [
                    'product' => $product->id, 'url' => Redact::secrets($url), 'status' => $response->status(),
                ]);

                return null;
            }

            $bytes = $response->body();

            if ($altaImage) {
                $extension = $this->altaRasterExtension($bytes);

                if ($extension === null) {
                    $this->noteFailure($url);
                    Log::channel('import')->warning('image response was not a valid raster', [
                        'product' => $product->id, 'url' => Redact::secrets($url),
                    ]);

                    return null;
                }

                $path = preg_replace('/\.[^.\/]+$/', '.'.$extension, $path);
            }

            unset(self::$failures[$this->hostOf($url)]);

            if (Storage::disk(self::DISK)->put($path, $bytes) === false) {
                Log::channel('import')->error('image could not be written to disk', [
                    'product' => $product->id,
                    'path' => $path,
                    'disk' => self::DISK,
                    'root' => config('filesystems.disks.'.self::DISK.'.root'),
                ]);

                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            $this->noteFailure($url);

            Log::channel('import')->warning('image download error: '.Redact::secrets($e->getMessage()), [
                'product' => $product->id, 'url' => Redact::secrets($url),
            ]);

            return null;
        }
    }

    protected function altaRasterExtension(string $bytes): ?string
    {
        if (strlen($bytes) > 1048576) {
            return null;
        }

        $image = @getimagesizefromstring($bytes);

        if (! $image || $image[0] < 1 || $image[1] < 1 || $image[0] > 12_000 || $image[1] > 12_000
            || $image[0] * $image[1] > 40_000_000) {
            return null;
        }

        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$image['mime'] ?? ''] ?? null;
    }

    protected function hostIsDown(string $url): bool
    {
        return (self::$failures[$this->hostOf($url)] ?? 0) >= self::FAILURES_BEFORE_SKIP;
    }

    protected function noteFailure(string $url): void
    {
        $host = $this->hostOf($url);
        self::$failures[$host] = (self::$failures[$host] ?? 0) + 1;

        if (self::$failures[$host] === self::FAILURES_BEFORE_SKIP) {
            Log::channel('import')->warning('image host skipped for this run', ['host' => $host]);
        }
    }

    protected function hostOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'unknown');
    }

    protected function addressOf(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['path'])) {
            return $url;
        }

        $path = implode('/', array_map(
            fn (string $segment) => rawurlencode(rawurldecode($segment)),
            explode('/', $parts['path']),
        ));

        return ($parts['scheme'] ?? 'https').'://'
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    protected function extension(string $url): string
    {
        $ext = $this->extensionOf($url);

        if ($ext === null) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $ext = $this->extensionOf((string) ($query['url'] ?? ''));
        }

        return $ext ?? 'jpg';
    }

    protected function extensionOf(string $url): ?string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $ext : null;
    }
}
