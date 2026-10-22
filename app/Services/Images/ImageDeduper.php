<?php

namespace App\Services\Images;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ImageDeduper
{
    protected const DISK = 'public';

    /*
     | A supplier feed often lists the same picture twice — under a different
     | name, with a cache-busting query, or simply repeated. Each copy became
     | its own row, and the card then rotated between identical photos.
     */
    public function prune(Product $product, bool $dry = false): int
    {
        /* The column arrived with this feature; on a database that has not
           caught up yet there is nothing to compare against. */
        if (! $this->ready()) {
            return 0;
        }

        $images = $product->images()->orderBy('sort_order')->orderBy('id')->get();

        $seen = [];
        $drop = [];

        foreach ($images as $image) {
            $hash = $this->hashOf($image);

            /* A file that is missing from disk cannot be compared; leave it be. */
            if ($hash === null) {
                continue;
            }

            if (in_array($hash, $seen, true)) {
                $drop[] = $image;

                continue;
            }

            $seen[] = $hash;
        }

        if ($dry) {
            return count($drop);
        }

        foreach ($drop as $image) {
            $this->forget($image);
        }

        $this->stamp($product->images()->orderBy('sort_order')->orderBy('id')->get());

        return count($drop);
    }

    protected static ?bool $ready = null;

    public function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('product_images', 'hash');
    }

    /* Remote files are only known by their address; local ones by their bytes. */
    public function hashOf(ProductImage $image): ?string
    {
        if ($image->hash) {
            return $image->hash;
        }

        if ($image->isRemote()) {
            return sha1($image->path);
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($image->path)) {
            return null;
        }

        return sha1((string) $disk->get($image->path));
    }

    /* Writes the hash the next run compares against, and closes the gaps the
       removed rows left in the order. */
    protected function stamp($images): void
    {
        $position = 0;

        foreach ($images as $image) {
            $hash = $this->hashOf($image);

            if ($image->hash !== $hash || $image->sort_order !== $position) {
                $image->forceFill(['hash' => $hash, 'sort_order' => $position])->saveQuietly();
            }

            $position++;
        }
    }

    /* The file goes too, unless another row still points at it. */
    protected function forget(ProductImage $image): void
    {
        $path = $image->path;
        $remote = $image->isRemote();

        $image->delete();

        if ($remote || ProductImage::where('path', $path)->exists()) {
            return;
        }

        foreach (Thumbnailer::WIDTHS as $width) {
            Storage::disk(Thumbnailer::DISK)->delete(Thumbnailer::pathFor($path, $width));
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
