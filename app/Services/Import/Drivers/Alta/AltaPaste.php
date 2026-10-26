<?php

namespace App\Services\Import\Drivers\Alta;

/*
 | Several answers pasted in together.
 |
 | Nobody pastes one product at a time when they have two hundred of them, and
 | what they paste is whatever the shell left behind: answers one per line, or
 | run together, or wrapped in an array. All three are read the same way.
 */
class AltaPaste
{
    /**
     * Each answer in the paste, in the order it was written.
     *
     * @return array<int, string>
     */
    public static function split(string $pasted): array
    {
        $pasted = trim($pasted);

        if ($pasted === '') {
            return [];
        }

        /* An array of answers, which is what a script collecting them writes. */
        $whole = json_decode($pasted, true);

        if (is_array($whole) && array_is_list($whole) && $whole !== []) {
            return array_map(
                fn ($one) => is_string($one) ? $one : json_encode($one, JSON_UNESCAPED_UNICODE),
                $whole,
            );
        }

        /* A single answer, whole and well formed. */
        if (is_array($whole)) {
            return [$pasted];
        }

        return self::objects($pasted);
    }

    /*
     | Walks the text and cuts it wherever one object closes and the next
     | begins. Counting braces is enough as long as the ones inside quoted
     | text are left out of the count -- and product names do carry them.
     *
     * @return array<int, string>
     */
    protected static function objects(string $text): array
    {
        $found = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;

        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }

                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0 && $start !== null) {
                    $found[] = substr($text, $start, $i - $start + 1);
                    $start = null;
                }

                if ($depth < 0) {
                    /* A stray closing brace: start counting afresh rather than
                       swallowing everything after it. */
                    $depth = 0;
                }
            }
        }

        /* Nothing that looks like an object at all -- hand the text back whole
           so the reader can say what is wrong with it. */
        return $found ?: [$text];
    }
}
