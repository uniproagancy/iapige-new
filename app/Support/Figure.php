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
    public static function money(mixed $value): ?float
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        /* A plain number is taken as written -- except one with exactly three
           figures after the point, because no price is quoted to a thousandth
           of a lari and "2.599" is far likelier to be two and a half thousand
           written the European way. */
        if (is_numeric($text) && ! preg_match('/[.,]\d{3}$/', $text)) {
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
        $grouping = substr_count($text, $separator) > 1
            || (strlen($part) === 3 && $whole !== '' && $whole !== '0');

        return $grouping
            ? (float) preg_replace('/\D+/', '', $text)
            : self::split($text, $at);
    }

    /*
     | The same reading, for a caller that would rather have nothing than a
     | null to carry about.
     */
    public static function amount(mixed $value): float
    {
        return self::money($value) ?? 0.0;
    }

    protected static function split(string $text, int $at): float
    {
        $whole = preg_replace('/\D+/', '', substr($text, 0, $at));
        $part = preg_replace('/\D+/', '', substr($text, $at + 1));

        return (float) (($whole === '' ? '0' : $whole).'.'.($part === '' ? '0' : $part));
    }
}
