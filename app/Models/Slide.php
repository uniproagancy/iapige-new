<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One slide of the home page's carousel.
 *
 * @property string|null $dates
 * @property string|null $title
 * @property string|null $sub
 * @property string|null $cta
 */
class Slide extends Model
{
    use HasTranslations;

    protected array $translatable = ['dates', 'title', 'sub', 'cta'];

    protected $fillable = ['image', 'url', 'is_active', 'starts_at', 'ends_at', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /** Showing right now — switched on, and inside its dates if it has any. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') || str_starts_with($this->image, '/')
            ? $this->image
            : Storage::disk('public')->url($this->image);
    }

    /**
     * Whether this slide is worth drawing.
     *
     * A picture with nothing written on it is a banner; words with no picture
     * are a blank rectangle. Either on its own is fine — both missing is not
     * a slide at all, and one saved half-finished should not leave a hole in
     * the carousel.
     */
    public function isDrawable(): bool
    {
        return (bool) ($this->image || $this->title);
    }
}
