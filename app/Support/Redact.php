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

    /* The same secrets, found by name anywhere in a decoded message. */
    public static function fields(array $data): array
    {
        $names = array_map('strtolower', self::SECRET_PARAMS);

        foreach ($data as $name => $value) {
            if (is_string($name) && in_array(strtolower($name), $names, true)) {
                $data[$name] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$name] = self::fields($value);
            }
        }

        return $data;
    }

    /* A message kept as text: JSON fields by name, and anything written as
       name=value. */
    public static function json(string $text): string
    {
        foreach (self::SECRET_PARAMS as $param) {
            $text = (string) preg_replace(
                '/("'.preg_quote($param, '/').'"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
                '$1"[redacted]"',
                $text,
            );
        }

        return self::secrets($text);
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
