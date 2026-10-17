<?php

namespace App\Models;

use App\Services\Images\Thumbnailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'sort_order', 'has_thumbs'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'has_thumbs' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isRemote(): bool
    {
        return str_starts_with($this->path, 'http');
    }

    public function url(): string
    {
        return $this->isRemote()
            ? $this->path
            : Storage::disk('public')->url($this->path);
    }

    public function variant(int $width): string
    {
        if (! $this->has_thumbs || $this->isRemote()) {
            return $this->url();
        }

        return Storage::disk(Thumbnailer::DISK)->url(Thumbnailer::pathFor($this->path, $width));
    }

    public function srcset(array $widths = [320, 640, 1280]): string
    {
        if (! $this->has_thumbs || $this->isRemote()) {
            return '';
        }

        return collect($widths)
            ->map(fn (int $width) => $this->variant($width).' '.$width.'w')
            ->implode(', ');
    }
}
