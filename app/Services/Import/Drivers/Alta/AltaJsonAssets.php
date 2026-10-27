<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;
use JsonException;

class AltaJsonAssets
{
    protected const MAX_PIXELS = 40_000_000;

    protected const MAX_DIMENSION = 12_000;

    /** Read and validate optional photo bytes without writing or fetching anything. */
    public function validate(string $json, array $urls): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }

        if (! is_array($data) || ! array_key_exists('iapiAssets', $data)) {
            return null;
        }

        $assets = $data['iapiAssets'];

        if (! is_array($assets) || ! array_is_list($assets) || count($assets) > 8) {
            $this->invalid();
        }

        $wanted = $this->urls($urls);

        foreach ($assets as $asset) {
            if (! is_array($asset) || ! isset($asset['url'], $asset['base64'])
                || ! is_string($asset['url']) || ! is_string($asset['base64'])
                || strlen($asset['base64']) > 1_398_104 || ! in_array($asset['url'], $wanted, true)) {
                $this->invalid();
            }
        }

        $valid = AltaImageCache::validate($assets, $wanted);
        $this->dimensions($valid);

        return $valid;
    }

    /** The caller must validate again at save time; preview never calls this. */
    public function store(Supplier $supplier, array $assets): void
    {
        $directory = $this->directory($supplier);

        if ($directory === null) {
            $this->invalid();
        }

        $encoded = [];

        foreach ($assets as $url => $bytes) {
            if (! is_string($url) || ! is_string($bytes)) {
                $this->invalid();
            }

            $encoded[] = ['url' => $url, 'base64' => base64_encode($bytes)];
        }

        $valid = $this->validate(json_encode(['iapiAssets' => $encoded], JSON_THROW_ON_ERROR), array_keys($assets));
        AltaImageCache::store($valid, $directory);
    }

    /** Check content hashes only. Existing photo associations are never pruned. */
    public function verify(Product $product, Supplier $supplier, array $urls): bool
    {
        $directory = $this->directory($supplier);

        if ($directory === null) {
            return false;
        }

        try {
            $wanted = $this->urls($urls);
        } catch (ValidationException) {
            return false;
        }

        return AltaImageCache::verifyProduct($product, $wanted, $directory, prune: false);
    }

    protected function urls(array $urls): array
    {
        $wanted = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                $this->invalid();
            }

            $url = trim($url);

            if ($url === '' || in_array($url, $wanted, true)) {
                continue;
            }

            if (count($wanted) === 8) {
                break;
            }

            $parts = parse_url($url);
            $path = $parts['path'] ?? '';
            $decoded = rawurldecode($path);

            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || ! in_array($parts['host'] ?? '', ['imgstore.alta.ge', 'alta.ge'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
                || ! str_starts_with($path, '/images/') || str_contains($decoded, '\\')
                || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded)
                || preg_match('/[\x00-\x20\x7f]/', $decoded)
                || ! preg_match('/\.(?:jpe?g|png|webp|gif)\z/iD', $path)) {
                $this->invalid();
            }

            $wanted[] = $url;
        }

        return $wanted;
    }

    protected function dimensions(array $assets): void
    {
        foreach ($assets as $bytes) {
            $image = @getimagesizefromstring($bytes);

            if (! $image || $image[0] < 1 || $image[1] < 1
                || $image[0] > self::MAX_DIMENSION || $image[1] > self::MAX_DIMENSION
                || $image[0] * $image[1] > self::MAX_PIXELS) {
                $this->invalid();
            }
        }
    }

    protected function directory(Supplier $supplier): ?string
    {
        if ($supplier->code !== 'alta' || $supplier->driver !== AltaDriver::class) {
            return null;
        }

        $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);
        $directory = $config['retail_directory'] ?? storage_path('app/private/alta-retail');

        return is_string($directory) && trim($directory) !== '' ? $directory : null;
    }

    protected function invalid(): never
    {
        throw ValidationException::withMessages(['iapiAssets' => __('admin.alta_invalid_export')]);
    }
}
