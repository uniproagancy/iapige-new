<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\ImageDeduper;
use App\Services\Images\Thumbnailer;
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

        /* Two addresses can still hand back the same picture. */
        app(ImageDeduper::class)->prune($product->fresh());
    }

    protected function store(Product $product, string $url, int $index): ?string
    {
        $path = sprintf(
            'products/%d/%s-%02d-%s.%s',
            $product->id,
            Str::slug(Str::limit($product->sku, 40, '')) ?: 'p'.$product->id,
            $index + 1,
            substr(sha1($url), 0, 12),
            $this->extension($url),
        );

        if (Storage::disk(self::DISK)->exists($path)) {
            return $path;
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

            unset(self::$failures[$this->hostOf($url)]);

            if (Storage::disk(self::DISK)->put($path, $response->body()) === false) {
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
