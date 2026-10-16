<?php

namespace App\Support;

class Text
{
    public static function utf8(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $substitute = mb_substitute_character();

        mb_substitute_character('none');

        try {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }

        return trim($text, " \t\n\r\0\x0B,;:-–—/|");
    }

    public static function utf8All(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $key = is_string($key) ? (string) self::utf8($key) : $key;

            $clean[$key] = match (true) {
                is_array($value) => self::utf8All($value),
                is_string($value) => self::utf8($value),
                default => $value,
            };
        }

        return $clean;
    }
}
