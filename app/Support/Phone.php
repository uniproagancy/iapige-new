<?php

namespace App\Support;

class Phone
{
    public static function dial(?string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        $digits = (str_starts_with($digits, '+') ? '+' : '').str_replace('+', '', $digits);

        if (str_starts_with($digits, '+')) {
            return strlen($digits) > 1 ? $digits : null;
        }

        $country = (string) config('shop.phone_country', '995');

        if (strlen($digits) > 1 && str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        if ($country !== '' && strlen($digits) === 9) {
            return '+'.$country.$digits;
        }

        if ($country !== '' && str_starts_with($digits, $country)) {
            return '+'.$digits;
        }

        return $digits;
    }

    public static function digits(?string $raw): ?string
    {
        $number = self::dial($raw);

        return $number === null ? null : ltrim($number, '+');
    }

    public static function link(?string $raw, ?string $scheme = null): ?string
    {
        $number = self::dial($raw);

        if ($number === null) {
            return null;
        }

        $scheme = $scheme ?: (config('shop.dial_scheme') ?: 'tel');

        return $scheme.':'.$number;
    }
}
