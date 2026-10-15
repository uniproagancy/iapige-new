<?php

namespace App\Support;

/**
 * Turning what a customer typed into something a telephone can dial.
 *
 * People write their number the way they say it — "599 12 34 56",
 * "(032) 212-10-28", "+995 599 123456" — and all of those are the same
 * number. A softphone is handed one string and dials exactly it, so the
 * cleaning has to happen here rather than being hoped for.
 */
class Phone
{
    /**
     * The number as a telephone would dial it, or null if there is nothing
     * dialable in what was given.
     */
    public static function dial(?string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        // "00995…" is the same thing written for a landline keypad
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        // a + anywhere but the front is somebody's typo, not a country code
        $digits = (str_starts_with($digits, '+') ? '+' : '').str_replace('+', '', $digits);

        if (str_starts_with($digits, '+')) {
            return strlen($digits) > 1 ? $digits : null;
        }

        $country = (string) config('shop.phone_country', '995');

        /*
         * The trunk zero.
         *
         * A landline is written "032 212 10 28" — the leading zero is how you
         * reach another city from a telephone in this country, and it is not
         * part of the number. Left on, it made a ten digit string that fell
         * through every rule below and was dialled exactly as typed.
         */
        if (strlen($digits) > 1 && str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        /*
         * A nine-digit number is the Georgian national format — 5xx for a
         * mobile, 3xx for a landline — and it is what nearly every customer
         * leaves. Anything else is taken as already complete: an eleven digit
         * number that starts with the country code, or a foreign one.
         */
        if ($country !== '' && strlen($digits) === 9) {
            return '+'.$country.$digits;
        }

        if ($country !== '' && str_starts_with($digits, $country)) {
            return '+'.$digits;
        }

        return $digits;
    }

    /**
     * The same number as bare digits, country code and all.
     *
     * What an SMS gateway wants: smsoffice.ge takes 995XXXXXXXXX and refuses
     * a plus, while a softphone wants the plus. One cleaning, two shapes.
     */
    public static function digits(?string $raw): ?string
    {
        $number = self::dial($raw);

        return $number === null ? null : ltrim($number, '+');
    }

    /**
     * The href the admin's call button carries.
     *
     * The scheme decides which program answers it; see shop.dial_scheme.
     */
    public static function link(?string $raw, ?string $scheme = null): ?string
    {
        $number = self::dial($raw);

        if ($number === null) {
            return null;
        }

        // the key may be set and empty, which the config default does not cover
        $scheme = $scheme ?: (config('shop.dial_scheme') ?: 'tel');

        /*
         * sip: wants a user at a host, and the host is the one thing we
         * cannot know from here — so the bare number is handed over and the
         * softphone's own account supplies the rest, which is what Zoiper
         * does with a number typed into it by hand.
         */
        return $scheme.':'.$number;
    }
}
