<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    public function isDrawable(): bool
    {
        return (bool) ($this->image || $this->title);
    }
}
