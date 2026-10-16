<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class UiTranslation extends Model
{
    protected $fillable = ['group', 'key', 'locale', 'value'];

    protected static function booted(): void
    {
        static::saved(fn (UiTranslation $t) => $t->forgetCache());
        static::deleted(fn (UiTranslation $t) => $t->forgetCache());
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'locale', 'code');
    }

    public static function cacheKey(string $locale, string $group): string
    {
        return "ui_translations.{$locale}.{$group}";
    }

    public static function lines(string $locale, string $group): array
    {
        try {
            return Cache::rememberForever(static::cacheKey($locale, $group), fn () => static::query()
                ->where('locale', $locale)
                ->where('group', $group)
                ->whereNotNull('value')
                ->pluck('value', 'key')
                ->all());
        } catch (QueryException) {
            return [];
        }
    }

    public function forgetCache(): void
    {
        Cache::forget(static::cacheKey($this->locale, $this->group));

        if ($this->wasChanged(['locale', 'group'])) {
            Cache::forget(static::cacheKey($this->getOriginal('locale'), $this->getOriginal('group')));
        }
    }
}
