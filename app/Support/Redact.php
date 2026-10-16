<?php

namespace App\Support;

class Redact
{
    protected const SECRET_PARAMS = [
        'token', 'accessToken', 'access_token', 'cfClearance', 'cf_clearance',
        'apikey', 'api_key', 'key', 'secret', 'password',
    ];

    protected const SECRET_COOKIES = [
        'zoommer-access_token', 'cf_clearance',
    ];

    public static function secrets(?string $text): string
    {
        if (! $text) {
            return (string) $text;
        }

        foreach (self::SECRET_PARAMS as $param) {
            $text = (string) preg_replace(

                '/(\b'.preg_quote($param, '/').'=)[^&;\s"\'\]]+/i',
                '$1[redacted]',
                $text,
            );
        }

        foreach (self::SECRET_COOKIES as $cookie) {
            $text = (string) preg_replace(
                '/('.preg_quote($cookie, '/').'=)[^;\s"\']+/i',
                '$1[redacted]',
                $text,
            );
        }

        return $text;
    }

    public static function phone(?string $number): string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return '';
        }

        if (mb_strlen($number) <= 6) {
            return str_repeat('*', max(0, mb_strlen($number) - 2)).mb_substr($number, -2);
        }

        return mb_substr($number, 0, 3).str_repeat('*', mb_strlen($number) - 7).mb_substr($number, -4);
    }
}
