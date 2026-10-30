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

    /*
     | A supplier's line as it reads once the way it was typed stops mattering:
     | entities decoded, letters lowered, runs of space made one. Everything
     | that compares two written answers starts here.
     */
    public static function tidy(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(mb_strtolower((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /*
     | The same, with everything that is not kept turned into a space -- so
     | that "Black/Orange" reads as two words rather than one, and a figure
     | written "4,5" reads as the two it is written as.
     */
    public static function words(?string $text, string $keep = '\p{L}\p{N}'): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^'.$keep.'\s]+/u', ' ', mb_strtolower($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
