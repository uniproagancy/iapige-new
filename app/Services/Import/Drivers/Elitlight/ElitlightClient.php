<?php

namespace App\Services\Import\Drivers\Elitlight;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElitlightClient
{
    public const CACHE_KEY = 'elitlight.catalogue';

    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /* The shop is one page; its catalogue lives in a js file as a plain array,
       which is steadier to read than the markup the page builds from it. */
    public function catalogue(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes((int) ($this->config['cache_minutes'] ?? 60)),
            fn () => $this->download(),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function variant(string $code): ?array
    {
        return $this->catalogue()[self::normalise($code)] ?? null;
    }

    /* The price list carries hand-typed markers in front of some codes. */
    public static function normalise(string $code): string
    {
        $code = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($code));
        $code = preg_replace('/^[\x{4E00}-\x{9FFF}]+/u', '', (string) $code);

        return mb_strtolower(trim((string) $code));
    }

    protected function download(): array
    {
        $url = rtrim((string) ($this->config['base_url'] ?? 'https://elitlight.ge'), '/')
            .'/'.ltrim((string) ($this->config['catalogue_path'] ?? 'js/trees.js'), '/');

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 30))->get($url);
        } catch (\Throwable $e) {
            Log::channel('import')->error('elitlight catalogue unreachable', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::channel('import')->error('elitlight catalogue refused', ['status' => $response->status()]);

            return [];
        }

        return $this->parse($response->body());
    }

    public function parse(string $javascript): array
    {
        $start = strpos($javascript, '[');
        $end = strrpos($javascript, ']');

        if ($start === false || $end === false || $end <= $start) {
            Log::channel('import')->error('elitlight catalogue is not an array');

            return [];
        }

        $models = json_decode(substr($javascript, $start, $end - $start + 1), true);

        if (! is_array($models)) {
            Log::channel('import')->error('elitlight catalogue did not decode', ['error' => json_last_error_msg()]);

            return [];
        }

        $variants = [];

        foreach ($models as $model) {
            foreach ($model['s'] ?? [] as $size) {
                $code = trim((string) ($size['c'] ?? ''));

                if ($code === '') {
                    continue;
                }

                $variants[self::normalise($code)] = [
                    'code' => $code,
                    'model_id' => $model['id'] ?? null,
                    'height' => isset($size['h']) ? (int) $size['h'] : null,
                    'price' => isset($size['p']) ? (float) $size['p'] : null,
                    'names' => $model['n'] ?? [],
                    'descriptions' => $model['d'] ?? [],
                    'image' => $model['img'] ?? null,
                    'tags' => $model['tags'] ?? [],
                ];
            }
        }

        return $variants;
    }

    public function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) ($this->config['base_url'] ?? 'https://elitlight.ge'), '/').'/'.ltrim($path, '/');
    }
}
