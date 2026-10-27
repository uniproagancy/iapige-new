<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AltaImageCache
{
    protected const MAX_IMAGE_BYTES = 1048576;

    protected const MAX_TOTAL_BYTES = 2097152;

    protected const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public static function validate(array $assets, array $urls): array
    {
        $wanted = array_slice(array_values(array_unique($urls)), 0, 8);
        $valid = [];
        $total = 0;

        foreach ($assets as $asset) {
            $url = $asset['url'] ?? '';
            $bytes = base64_decode($asset['base64'] ?? '', true);
            $image = is_string($bytes) ? @getimagesizefromstring($bytes) : false;

            if (! in_array($url, $wanted, true) || isset($valid[$url]) || $bytes === false
                || strlen($bytes) > self::MAX_IMAGE_BYTES || ! $image || ! isset(self::TYPES[$image['mime'] ?? ''])) {
                throw ValidationException::withMessages(['assets' => __('admin.alta_invalid_export')]);
            }

            $total += strlen($bytes);

            if ($total > self::MAX_TOTAL_BYTES) {
                throw ValidationException::withMessages(['assets' => __('admin.alta_invalid_export')]);
            }

            $valid[$url] = $bytes;
        }

        if (array_diff($wanted, array_keys($valid)) !== []) {
            throw ValidationException::withMessages(['assets' => __('admin.alta_invalid_export')]);
        }

        return $valid;
    }

    public static function store(array $assets, string $directory): void
    {
        $directory = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'assets';
        File::ensureDirectoryExists($directory, 0700);

        foreach ($assets as $url => $bytes) {
            $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', $url).'.image';
            $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            File::put($temporary, $bytes);
            chmod($temporary, 0600);
            rename($temporary, $path);
        }
    }

    public static function read(string $directory, string $url, int $maximumAge = 86400): ?array
    {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.hash('sha256', $url).'.image';

        if (! is_file($path) || time() - filemtime($path) > $maximumAge || filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $image = @getimagesize($path);

        if (! $image || ! isset(self::TYPES[$image['mime'] ?? ''])) {
            return null;
        }

        return ['bytes' => File::get($path), 'hash' => hash_file('sha256', $path), 'extension' => self::TYPES[$image['mime']]];
    }

    public static function verifyProduct(Product $product, array $urls, string $directory, bool $prune = false): bool
    {
        $expected = [];

        foreach (array_slice(array_values(array_unique($urls)), 0, 8) as $url) {
            $cached = self::read($directory, $url);

            if (! $cached) {
                return false;
            }

            $expected[] = $cached['hash'];
        }

        $actual = [];
        $images = $product->images()->get();

        foreach ($images as $image) {
            $actual[$image->id] = Storage::disk('public')->exists($image->path)
                ? hash('sha256', Storage::disk('public')->get($image->path)) : null;
        }

        if (! $expected || array_diff($expected, array_values($actual)) !== []) {
            return false;
        }

        if ($prune) {
            foreach ($images as $image) {
                if (! in_array($actual[$image->id], $expected, true)) {
                    $image->delete();
                }
            }
        }

        return true;
    }
}
