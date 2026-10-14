<?php

namespace App\Support;

/**
 * Text that arrived from somewhere we do not control.
 *
 * A supplier's feed is bytes, not characters. A spreadsheet cell cut to a
 * length, an HTTP response that ended early, a description a shop truncated
 * for its own listing card — any of them can hand us a string whose last
 * character is only two thirds there, and a Georgian letter is three bytes, so
 * it happens to Georgian text far more often than to Latin.
 *
 * MySQL refuses such a string outright: "Incorrect string value: '\xE1\x83'".
 * Which would be fine if it stopped there, but the refusal lands in the middle
 * of an import — the attribute cannot be created, and the row that was going
 * to point at it fails too, as a foreign key violation several lines later
 * that says nothing about the real cause. One broken character took out a
 * product's whole specification table and filled the log with the wrong error.
 */
class Text
{
    /**
     * The same text with anything that is not valid UTF-8 removed.
     *
     * Repaired rather than rejected: the half character at the end is the only
     * broken part, and "მაცივრის მოცულობა ,ლ" minus its last letter is still
     * the name of the thing. Throwing the row away would lose a specification
     * over a byte.
     */
    public static function utf8(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            /*
             * Dropped, not replaced.
             *
             * mb_convert_encoding substitutes "?" for a byte it cannot read,
             * which turns a half character into a question mark sitting in the
             * middle of a product name — tidier than a crash and still wrong.
             * The substitute is switched off for the conversion and put back
             * afterwards, because it is a global setting and something else
             * may be relying on it.
             */
            $substitute = mb_substitute_character();

            mb_substitute_character('none');

            try {
                $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            } finally {
                mb_substitute_character($substitute);
            }
        }

        /*
         * A truncated string usually ends mid-phrase, so it is left with the
         * punctuation that was joining it to the part that never arrived.
         * "მაცივრის მოცულობა ," reads better as "მაცივრის მოცულობა".
         */
        return trim($text, " \t\n\r\0\x0B,;:-–—/|");
    }

    /**
     * Every string in a nested array, repaired.
     *
     * Keys too: a spec is grouped by its name, so a broken one would split one
     * attribute into two.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
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
