<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Redact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File names carry the sku, the position and a hash of the source URL:
 *     products/142/nrd-p14-4070-01-9c3f1a2b4d5e.jpg
 * The hash makes the name unique and lets a second run skip a file it already
 * has, instead of downloading the same image again.
 */
class ImageDownloader
{
    protected const DISK = 'public';

    /** How many photographs of one product are worth keeping. */
    public const MAX_IMAGES = 8;

    /*
     | A photograph is worth waiting a few seconds for, not a few minutes.
     |
     | The old budget was 30s read + 10s connect with two retries, per image,
     | for up to eight images: a single product whose CDN had gone quiet could
     | hold the importer for twelve minutes. These numbers cap one product's
     | gallery at roughly half a minute.
     */
    /*
     | Connecting is the slow part, not the download.
     |
     | At five seconds s3.zoommer.ge lost whole galleries to what was only a
     | cold DNS and TLS handshake — one product came out of a thirty-id run with
     | no photographs at all. Ten is the figure that stops happening, and since
     | the gallery is fetched outside the transaction it costs nobody a lock;
     | a host that is genuinely down is still cut off after five tries.
     */
    protected const CONNECT_TIMEOUT = 10;

    protected const READ_TIMEOUT = 10;

    protected const RETRIES = 1;

    /**
     * How long one product's gallery may take, in seconds.
     *
     * Comfortably inside ImportProductJob::$timeout, with room left for the
     * API calls and the database work that share the same job.
     */
    protected const BUDGET = 40;

    protected const FAILURES_BEFORE_SKIP = 5;

    /** host => consecutive failures, for the life of this worker process */
    protected static array $failures = [];

    /** @param  array<int, string>  $urls */
    public function sync(Product $product, array $urls, int $limit = self::MAX_IMAGES): void
    {
        $deadline = microtime(true) + self::BUDGET;

        foreach (array_slice(array_values(array_filter($urls)), 0, $limit) as $i => $url) {
            /*
             * Eight photographs can each spend twenty seconds reaching a slow
             * host, which is far longer than the job is allowed to live. The
             * worker then kills it mid-download: no exception is recorded, only
             * a spent attempt, and after three of those the job dies of
             * "attempted too many times" with nothing in the log to say why.
             *
             * Stopping early is safe because import() asks again for whatever
             * is missing on the next run, and a file already on disk costs no
             * request at all — so the gallery fills in over a run or two
             * instead of taking the job down on the first.
             */
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
                // one unreachable photograph must not cost us the gallery
                Log::channel('import')->warning('image skipped', [
                    'product' => $product->id,
                    'url' => Redact::secrets($url),
                    'error' => Redact::secrets($e->getMessage()),
                ]);

                continue;
            }

            if ($path) {
                ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'sort_order' => $i],
                    ['path' => $path],
                );
            }
        }
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
                    // Windows PHP tries IPv6 first and waits out the timeout
                    'force_ip_resolve' => 'v4',
                ])
                ->withHeaders([
                    'User-Agent' => config('services.import.user_agent', 'Mozilla/5.0'),
                    // some CDNs refuse a request that arrives from nowhere
                    'Referer' => parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/',
                ])
                ->get($url);

            if (! $response->successful()) {
                $this->noteFailure($url);

                Log::channel('import')->warning('image download failed', [
                    'product' => $product->id, 'url' => Redact::secrets($url), 'status' => $response->status(),
                ]);

                return null;
            }

            unset(self::$failures[$this->hostOf($url)]);

            Storage::disk(self::DISK)->put($path, $response->body());

            return $path;
        } catch (\Throwable $e) {
            $this->noteFailure($url);

            Log::channel('import')->warning('image download error: '.Redact::secrets($e->getMessage()), [
                'product' => $product->id, 'url' => Redact::secrets($url),
            ]);

            return null;
        }
    }

    /* ------------------------------------------------------------------ circuit breaker */

    /**
     * A host that has failed this many times in a row is treated as down for
     * the rest of the run.
     *
     * Without this, a supplier whose image server is offline costs every one of
     * its products the full timeout budget — thousands of products each waiting
     * on the same dead host, one after another.
     */
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

    /**
     * The extension to store the file under.
     *
     * A proxied address carries the real one in its query rather than its path:
     * ...workers.dev?type=image&url=https%3A%2F%2Fstatic.ee.ge%2F...png
     *
     * Reading only the path there gives nothing, so every picture fetched
     * through a worker was saved as .jpg — and a PNG named .jpg is served with
     * the wrong content type, because the web server decides that from the
     * extension and has no reason to doubt it.
     */
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
