<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
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

    /** @param  array<int, string>  $urls */
    public function sync(Product $product, array $urls, int $limit = 8): void
    {
        foreach (array_slice(array_values(array_filter($urls)), 0, $limit) as $i => $url) {
            try {
                $path = $this->store($product, $url, $i);
            } catch (\Throwable $e) {
                // one unreachable photograph must not cost us the gallery
                Log::channel('import')->warning('image skipped', [
                    'product' => $product->id,
                    'url'     => $url,
                    'error'   => $e->getMessage(),
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

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->withOptions([
                    // Windows PHP tries IPv6 first and waits out the timeout
                    'force_ip_resolve' => 'v4',
                ])
                ->withHeaders([
                    'User-Agent' => config('services.import.user_agent', 'Mozilla/5.0'),
                    // some CDNs refuse a request that arrives from nowhere
                    'Referer'    => parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/',
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('image download failed', [
                    'product' => $product->id, 'url' => $url, 'status' => $response->status(),
                ]);

                return null;
            }

            Storage::disk(self::DISK)->put($path, $response->body());

            return $path;
        } catch (\Throwable $e) {
            Log::warning('image download error: '.$e->getMessage(), ['product' => $product->id, 'url' => $url]);

            return null;
        }
    }

    protected function extension(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $ext : 'jpg';
    }
}
