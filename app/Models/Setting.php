<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const CACHE_KEY = 'settings.all';

    protected $fillable = ['key', 'value'];

    public const MAP = [
        'shop.phone' => 'string',
        'shop.email' => 'string',
        'shop.delivery_days' => 'int',
        'shop.instalment_months' => 'int',
        'shop.invoice_valid_days' => 'int',
        'shop.min_order' => 'float',

        'shop.company.name' => 'string',
        'shop.company.tax_id' => 'string',
        'shop.company.address' => 'string',
        'shop.company.bank' => 'string',
        'shop.company.swift' => 'string',
        'shop.company.iban' => 'string',
        'shop.company.accounts' => 'string',

        'smsoffice.enabled' => 'bool',
        'smsoffice.sender' => 'string',
        'smsoffice.redirect_to' => 'string',

        'shop.dial_scheme' => 'string',
        'shop.phone_country' => 'string',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    public static function stored(): array
    {
        try {
            return Cache::rememberForever(
                self::CACHE_KEY,
                fn () => static::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException) {

            return [];
        }
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function apply(): void
    {
        foreach (static::stored() as $key => $value) {
            if (! isset(self::MAP[$key]) || $value === null || $value === '') {
                continue;
            }

            config([$key => match (self::MAP[$key]) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value,
            }]);
        }
    }

    public static function put(string $key, mixed $value): void
    {
        if (! isset(self::MAP[$key])) {
            return;
        }

        static::updateOrCreate(['key' => $key], [
            'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        ]);
    }
}
