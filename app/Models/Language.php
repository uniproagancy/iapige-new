<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use LogicException;

class Language extends Model
{
    public const CACHE_KEY = 'languages.active';

    protected $fillable = [
        'code', 'name', 'native_name', 'short_name', 'regional', 'script',
        'flag', 'is_default', 'is_active', 'sort_order',
    ];

    protected static ?Collection $memo = null;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {

        static::saving(function (Language $language) {
            if ($language->is_default) {
                $language->is_active = true;
            }
        });

        static::saved(function (Language $language) {
            if ($language->is_default) {
                static::whereKeyNot($language->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
            static::flushCache();
        });

        static::deleting(function (Language $language) {
            if ($language->is_default) {
                throw new LogicException('The default language cannot be deleted — make another language default first.');
            }
        });

        static::deleted(fn () => static::flushCache());
    }

    public static function active(): Collection
    {
        if (static::$memo !== null) {
            return static::$memo;
        }

        try {

            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map->getAttributes()
                ->all());

            return static::$memo = static::hydrate($rows);
        } catch (QueryException) {
            return new Collection;
        }
    }

    public static function activeCodes(): array
    {
        return static::active()->pluck('code')->all();
    }

    public static function isActive(string $code): bool
    {
        return in_array($code, static::activeCodes(), true);
    }

    public static function defaultCode(): string
    {
        return static::active()->firstWhere('is_default', true)?->code ?? config('app.locale', 'ka');
    }

    public static function supportedLocales(): array
    {
        return static::active()->mapWithKeys(fn (Language $l) => [$l->code => [
            'name' => $l->name,
            'native' => $l->native_name,
            'script' => $l->script ?: 'Latn',
            'regional' => $l->regional ?: $l->code,
            'short' => $l->short_name ?: mb_substr($l->native_name, 0, 3),
        ]])->all();
    }

    public static function flushCache(): void
    {
        static::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
