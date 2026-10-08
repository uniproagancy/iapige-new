<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Named disk, not the default one.
     *
     * Both the importer and the admin store on "public", while this asked the
     * default disk — which is "local", rooted in storage/app/private. The two
     * happen to produce the same /storage/… path, so the pictures load only
     * because the symlink answers before Laravel's own route does. Saying
     * which disk holds them stops that being a coincidence.
     */
    public function url(): string
    {
        return str_starts_with($this->path, 'http')
            ? $this->path
            : Storage::disk('public')->url($this->path);
    }
}
