<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Support\Facades\Lang;

/*
 | Suppliers write specifications in English, and the Georgian shop showed
 | "Black", "Wired", "Cord Length (m)" as they came. The glossary in
 | lang/ka/spec_terms.php gives the Georgian; the English is not thrown away
 | but moved to the English row, so the English site still reads as before.
 |
 | Only whole terms are replaced. "Black/Silver" and "Wired, Wireless" are
 | lists, read piece by piece; anything not in the glossary -- a brand, a
 | model, "HDMI" -- is left exactly as the maker wrote it.
 */
final class SpecTerms
{
    public const SOURCE = 'en';

    private static ?array $terms = null;

    public static function georgian(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '' || preg_match('/\p{Georgian}/u', $text)) {
            return null;
        }

        if (($whole = self::term($text)) !== null) {
            return $whole;
        }

        // "For Apple" is a case made for Apple
        if (preg_match('/^for\s+(.+)$/i', $text, $m)) {
            return (self::term($m[1]) ?? trim($m[1])).'-ისთვის';
        }

        $parts = preg_split('/(\s*[,\/]\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($parts) < 3) {
            return null;
        }

        $changed = false;

        foreach ($parts as $at => $part) {
            if ($at % 2 === 1) {
                continue;
            }

            if (($said = self::term($part)) !== null) {
                $parts[$at] = $said;
                $changed = true;
            } elseif (preg_match('/[a-z]{3,}/', $part)) {
                /* An English word the glossary does not know: half a list in
                   Georgian reads worse than all of it in English. */
                return null;
            }
        }

        return $changed ? implode('', $parts) : null;
    }

    /*
     | Lines of one owner (a spec, a value) keyed by locale, each with $field.
     | The default-language line is given its Georgian; the English it had goes
     | to the English line, unless the supplier already sent one.
     */
    public static function lines(array $byLocale, string $field): array
    {
        $default = Language::defaultCode();
        $said = self::georgian($byLocale[$default][$field] ?? null);

        if ($said === null) {
            return $byLocale;
        }

        if (! filled($byLocale[self::SOURCE][$field] ?? null)) {
            $byLocale[self::SOURCE] = [$field => $byLocale[$default][$field]] + ($byLocale[self::SOURCE] ?? []);
        }

        $byLocale[$default][$field] = $said;

        return $byLocale;
    }

    private static function term(string $text): ?string
    {
        self::$terms ??= collect((array) Lang::get('spec_terms', [], 'ka', false))
            ->filter(fn ($v) => is_string($v))
            ->mapWithKeys(fn ($v, $k) => [mb_strtolower(trim((string) $k)) => $v])
            ->all();

        return self::$terms[mb_strtolower(trim($text))] ?? null;
    }
}
