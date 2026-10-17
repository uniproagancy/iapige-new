<?php

namespace App\Services\Images;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Thumbnailer
{
    public const WIDTHS = [320, 640, 1280];

    public const DISK = 'public';

    protected const QUALITY = 78;

    protected const MAX_PIXELS = 40_000_000;

    public function make(ProductImage $image, bool $force = false): bool
    {
        if (! self::supported() || $image->isRemote()) {
            return false;
        }

        if ($image->has_thumbs && ! $force) {
            return true;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($image->path)) {
            return false;
        }

        $source = @imagecreatefromstring($disk->get($image->path));

        if (! $source) {
            Log::channel('single')->warning('thumbnail: unreadable image', ['path' => $image->path]);

            return false;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width * $height > self::MAX_PIXELS) {
            imagedestroy($source);

            return false;
        }

        $written = 0;

        foreach (self::WIDTHS as $target) {
            if ($this->write($source, $width, $height, $target, $image->path)) {
                $written++;
            }
        }

        imagedestroy($source);

        if ($written === 0) {
            return false;
        }

        $image->forceFill(['has_thumbs' => true])->save();

        return true;
    }

    public static function pathFor(string $original, int $width): string
    {
        return 'thumbs/'.$width.'/'.preg_replace('/\.[^.\/]+$/', '', $original).'.webp';
    }

    public static function supported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    protected function write($source, int $width, int $height, int $target, string $original): bool
    {
        $scale = min(1, $target / $width);

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $ok = imagewebp($canvas, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);

        if (! $ok || $bytes === '') {
            return false;
        }

        return Storage::disk(self::DISK)->put(self::pathFor($original, $target), $bytes) !== false;
    }

    public function forget(ProductImage $image): void
    {
        if ($image->isRemote()) {
            return;
        }

        foreach (self::WIDTHS as $width) {
            Storage::disk(self::DISK)->delete(self::pathFor($image->path, $width));
        }
    }
}
