<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/*
 | An English product name in the Georgian shop, said the way a Georgian shop
 | says it: what the thing is first, in Georgian, then the brand and model
 | untouched. "Dreame Robot Vacuum X60 Ultra" becomes "რობოტი მტვერსასრუტი
 | Dreame X60 Ultra". A name with no phrase from lang/ka/product_types.php
 | in it -- most laptops, "DELL Pro 15 Essential" -- is left as it is.
 */
final class ProductNames
{
    private static ?array $types = null;

    public static function georgian(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '' || preg_match('/\p{Georgian}/u', $name)) {
            return null;
        }

        foreach (self::types() as $english => $georgian) {
            $pattern = '/(?<![A-Za-z])'.preg_quote($english, '/').'(?![A-Za-z])(\s+for(?![A-Za-z]))?/i';

            if (! preg_match($pattern, $name, $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            /* What a thing is gets said early. Far down the name, or after a
               "+" or "with", it is what comes in the box: "Blackview MP50 ...
               KB+Mouse" is a computer, not a mouse. */
            $before = substr($name, 0, $found[0][1]);

            if (count(preg_split('/\s+/', trim($before), -1, PREG_SPLIT_NO_EMPTY)) > 6
                || preg_match('/(?:[+&\/]|\b(?:with|and|plus|including|incl\.?)\s+)$/i', $before)) {
                continue;
            }

            /* "Protective Case for iPhone 15": the "for" goes with the phrase,
               or the name would read "დამცავი ქეისი for iPhone 15". */
            $rest = trim(preg_replace('/\s{2,}/', ' ', preg_replace($pattern, ' ', $name, 1)));
            $rest = trim(preg_replace('/^[\s,\-–—|]+|[\s,\-–—|]+$/u', '', $rest));

            return mb_substr(trim($georgian.' '.$rest), 0, 255);
        }

        return null;
    }

    /* Longest first, so "Gaming Mouse Pad" is not taken for a "Mouse". */
    private static function types(): array
    {
        if (self::$types === null) {
            $types = array_filter((array) Lang::get('product_types', [], 'ka', false), 'is_string');
            uksort($types, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            self::$types = $types;
        }

        return self::$types;
    }
}
