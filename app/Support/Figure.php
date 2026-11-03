<?php

namespace App\Support;

/*
 | Reading a figure a supplier wrote.
 |
 | The same price arrives half a dozen ways -- 2599, 2 599,00, 2,599.00,
 | 2.599,00 -- and treating every dot as a decimal point once turned
 | "2,599.00" into two lari and change, which put Marazzi's ovens on the shop
 | at the price of a coffee. One reading, in one place, so there is only ever
 | one thing to put right.
 */
class Figure
{
    /*
     | Some price lists quote three decimals -- INGCO writes 82.116 and means
     | eighty-two lari -- and the guess below would read that as eighty-two
     | thousand. A supplier that is known to write it one way says so, and is
     | then taken at its word.
     */
    public const DOT = 'dot';

    public const COMMA = 'comma';

    public static function money(mixed $value, ?string $decimal = null): ?float
    {
        /* A number that arrives as a number -- from JSON, from the database --
           is already read. Turning it back into text to guess at would make
           1.079 from an API into a thousand and seventy-nine. */
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        /* A plain number is taken as written -- except one with exactly three
           figures after the point, because no price is quoted to a thousandth
           of a lari and "2.599" is far likelier to be two and a half thousand
           written the European way. */
        if (is_numeric($text) && ($decimal === self::DOT || ! preg_match('/[.,]\d{3}$/', $text))) {
            return (float) $text;
        }

        /* Spaces group thousands as readily as anything else, including the
           two that look like spaces and are not. */
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text);
        $text = (string) preg_replace('/[^\d.,]+/u', '', $text);

        if ($text === '') {
            return null;
        }

        $dot = strrpos($text, '.');
        $comma = strrpos($text, ',');

        if ($dot === false && $comma === false) {
            return (float) $text;
        }

        /* With both about, the rightmost is the decimal point and the other
           can only be grouping. */
        if ($dot !== false && $comma !== false) {
            return self::split($text, max($dot, $comma));
        }

        $at = $dot !== false ? $dot : $comma;
        $separator = $text[$at];
        $whole = substr($text, 0, $at);
        $part = substr($text, $at + 1);

        /* One separator with exactly three digits behind it is grouping:
           "2.599" is two and a half thousand, not two and a half. Nobody
           groups a leading nought, so that one is a decimal point. */
        /* Told which mark this supplier uses, there is nothing to guess:
           that mark is the decimal point and the other is grouping. */
        $told = match ($decimal) {
            self::DOT => $separator === '.',
            self::COMMA => $separator === ',',
            default => null,
        };

        $grouping = $told === null
            ? substr_count($text, $separator) > 1
                || (strlen($part) === 3 && $whole !== '' && $whole !== '0')
            : ! $told;

        return $grouping
            ? (float) preg_replace('/\D+/', '', $text)
            : self::split($text, $at);
    }

    /*
     | The same reading, for a caller that would rather have nothing than a
     | null to carry about.
     */
    public static function amount(mixed $value, ?string $decimal = null): float
    {
        return self::money($value, $decimal) ?? 0.0;
    }

    /*
     | An amount somebody typed into a form, read the same way and handed
     | back as text for the form's own checks: "12,5" becomes "12.5" and
     | "1 200" becomes "1200". Text that holds no figure is returned as it was
     | typed, so the form says it is wrong instead of taking a guess.
     */
    public static function typed(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        /* A supplier's "1 079 ლარი" is read past its words, but a person who
           typed "12abc" made a mistake, and saving 12 would hide it. */
        if (! preg_match('/^[\s\x{00A0}\x{202F}]*-?[\d\s\x{00A0}\x{202F}.,]+₾?[\s\x{00A0}\x{202F}]*$/u', $value)) {
            return $value;
        }

        $read = self::money($value);

        return $read === null
            ? $value
            : rtrim(rtrim(number_format($read, 2, '.', ''), '0'), '.');
    }

    protected static function split(string $text, int $at): float
    {
        $whole = preg_replace('/\D+/', '', substr($text, 0, $at));
        $part = preg_replace('/\D+/', '', substr($text, $at + 1));

        return (float) (($whole === '' ? '0' : $whole).'.'.($part === '' ? '0' : $part));
    }
}
