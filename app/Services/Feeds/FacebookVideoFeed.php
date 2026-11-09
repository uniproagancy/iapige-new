<?php

namespace App\Services\Feeds;

use App\Models\Product;
use App\Support\ProductMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FacebookVideoFeed extends FacebookFeed
{
    public const PATH = 'feeds/facebook-videos.csv';

    protected ?ProductMedia $productMedia = null;

    public function generate(): string
    {
        $previous = app()->getLocale();
        $temporary = null;
        $handle = null;

        try {
            app()->setLocale($this->config['locale']);
            $path = Storage::disk('local')->path(self::PATH);
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
                throw new RuntimeException('Facebook video feed directory could not be created.');
            }

            $temporary = tempnam($directory, '.facebook-videos-');
            if ($temporary === false || dirname($temporary) !== realpath($directory)) {
                throw new RuntimeException('Facebook video feed temporary file could not be created.');
            }

            $handle = fopen($temporary, 'wb');
            if ($handle === false || fputcsv($handle, ['id', 'video[0].url'], ',', '"', '') === false) {
                throw new RuntimeException('Facebook video feed header could not be written.');
            }

            $written = 0;
            // Reuse the main Facebook query and skip rules; unrelated products need no media lookups.
            $this->query()->where('sku', 'like', 'TREE1-%')->chunkById(200, function ($products) use ($handle, &$written): void {
                foreach ($products as $product) {
                    $name = trim((string) $product->name);
                    [$price] = $this->prices($product);

                    if ($this->skip($product, $name, $price, $this->image($product))) {
                        continue;
                    }

                    if (! $url = $this->videoUrl($product)) {
                        continue;
                    }

                    // Omitted products have no row: this source does not clear their catalog videos.
                    if (fputcsv($handle, [(string) $product->id, $url], ',', '"', '') === false) {
                        throw new RuntimeException('Facebook video feed row could not be written.');
                    }
                    $written++;
                }
            });

            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Facebook video feed could not be flushed.');
            }
            fclose($handle);
            $handle = null;

            if (! chmod($temporary, 0644) || ! rename($temporary, $path)) {
                throw new RuntimeException('Facebook video feed could not be published.');
            }
            $temporary = null;
            Log::info('facebook video feed generated', ['items' => $written]);

            return $path;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            app()->setLocale($previous);
        }
    }

    protected function videoUrl(Product $product): ?string
    {
        foreach (($this->productMedia ??= app(ProductMedia::class))->videos($product) as $video) {
            $candidate = $video['url'];
            if (! str_starts_with($candidate, '/') && ! str_starts_with($candidate, 'https://')) {
                continue;
            }
            if (str_starts_with($candidate, '//')) {
                continue;
            }

            $url = url($candidate);
            $parts = parse_url($url);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'iapi.ge'
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('~\A/storage/supplier-media/trees-1/[A-Za-z]/video-[12]-[a-f0-9]{12}\.mp4\z~D', $parts['path'] ?? '') !== 1) {
                continue;
            }

            return $url;
        }

        return null;
    }
}
