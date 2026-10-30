<?php

namespace App\Services\Import;

use App\Support\Slug;
use Illuminate\Support\Str;

/*
 | Some suppliers send every colour and every capacity as a product of its own
 | and say nothing about them belonging together. What ties them is their name
 | with the telling-apart parts taken out of it: once "Redmi 15 6GB/128GB Titan
 | Grey" and "Redmi 15 8GB/256GB Midnight Black" both read "redmi 15", they are
 | the same product offered twice over.
 |
 | Deliberately blunt: a key only groups, and a group only shows a picker when
 | more than one product carries it, so a key earned alone costs nothing.
 */
class VariantKey
{
    public const MAX_LENGTH = 64;

    /* Specification names, as suppliers write them, that say which one of a
       product this is rather than what the product is. */
    public const AXES = [
        'color', 'colour', 'ფერი',
        'internal memory', 'memory', 'ram', 'ram memory', 'built-in memory',
        'storage', 'capacity', 'volume', 'size',

        /* The same shelves named in Georgian. An attribute's code is the name
           run through Slug::make, which carries Georgian across into latin
           letters, so these are the codes as they are actually stored. */
        'feri', 'shida mekhsiereba', 'mekhsiereba', 'operatiuli mekhsiereba',
        'ram motsuloba', 'motsuloba', 'zoma',
    ];

    /* Memory is the one figure a name carries that the specifications spell
       differently -- "128GB" against "128 GB" -- so it is taken out of both.
       Watts, millilitres and millimetres are left in: "Sencor SHM 5400WH" and
       "SHM 5206WH" are two kettles, not one kettle twice. Everything else that
       tells variants apart arrives as a specification instead. */
    protected const UNITS = 'gb|tb|mb';

    /*
     | The same product is often listed by one supplier and specified by
     | another, so a telephone called "HONOR 600 Dual Black" can carry its
     | colour as "შავი" -- and then the name has to be read on its own. These
     | are colours and the words that only ever stand beside one; anything that
     | could name a product instead ("steel", "ice", "stone") is left out, and
     | a word is only taken out whole.
     */
    public const COLOURS = [
        'black', 'white', 'grey', 'gray', 'silver', 'gold', 'golden',
        'blue', 'green', 'red', 'pink', 'purple', 'violet', 'yellow',
        'orange', 'brown', 'beige', 'ivory', 'bronze', 'copper', 'navy',
        'teal', 'cyan', 'magenta', 'burgundy', 'olive', 'khaki', 'jade',
        'amber', 'rose', 'lilac', 'peach', 'turquoise', 'coral', 'mint',
        'lavender', 'charcoal', 'graphite', 'obsidian', 'onyx', 'slate',
        'titanium', 'titan', 'midnight', 'starlight', 'moonlight', 'phantom',
        'forest', 'aurora', 'velvet', 'sand', 'sky', 'mocha', 'taupe',
        'platinum', 'sapphire', 'emerald', 'lime', 'aqua', 'indigo',
        'ultramarine', 'cosmic', 'nebula', 'mystic',

        'shavi', 'tetri', 'nacrisperi', 'vertskhlisperi', 'okrosperi',
        'lurji', 'mtsvane', 'tsiteli', 'vardisperi', 'vardisferi',
        'iisperi', 'iisferi', 'qviteli', 'narinjisperi', 'kavisperi',
        'tsisperi', 'bezhi', 'shindisperi',
    ];

    /**
     * The name with everything that only tells variants apart removed.
     *
     * @param  array<int, string>  $distinguishing  values of the axes above, as the supplier wrote them
     */
    public static function base(string $name, array $distinguishing = []): string
    {
        $base = self::normalise($name);

        foreach ($distinguishing as $value) {
            $value = self::normalise((string) $value);

            if ($value === '' || $value === $base) {
                continue;
            }

            /* Whole words only, so stripping the colour "red" cannot turn
               "Redmi" into "mi". */
            $base = (string) preg_replace('/(?<=^|\s)'.preg_quote($value, '/').'(?=\s|$)/u', ' ', $base);
        }

        $base = (string) preg_replace('/(?<=^|\s)\d+(?:[.,]\d+)?\s?(?:'.self::UNITS.')(?=\s|$)/u', ' ', $base);

        $base = (string) preg_replace(
            '/(?<=^|\s)(?:'.implode('|', self::COLOURS).')(?=\s|$)/u', ' ', $base);

        return trim((string) preg_replace('/\s+/u', ' ', $base));
    }

    /**
     * What to file this product's family under, or null when the name says too
     * little for grouping to mean anything.
     *
     * @param  array<int, array<string, mixed>>  $specs  rows as a ProductPayload carries them
     */
    public static function fromSpecs(string $supplierCode, ?string $brandName, string $name, array $specs): ?string
    {
        return self::make($supplierCode, $brandName, $name, self::axisValues($specs));
    }

    /**
     * @param  array<int, string>  $distinguishing
     */
    public static function make(string $supplierCode, ?string $brandName, string $name, array $distinguishing): ?string
    {
        $base = self::base($name, $distinguishing);

        /* One word and nothing else is as likely to be a whole shelf as a
           family -- "cable", "charger" -- so it is left ungrouped. */
        if (mb_strlen($base) < 4 || ! str_contains($base, ' ')) {
            return null;
        }

        $brand = self::normalise((string) $brandName);

        /* The brand is usually the first word of the name already; saying it
           again only makes the key harder to read. */
        $slug = self::slug($brand !== '' && ! str_starts_with($base, $brand)
            ? $brand.' '.$base
            : $base);

        if ($slug === '') {
            return null;
        }

        $prefix = self::slug($supplierCode);
        $room = self::MAX_LENGTH - mb_strlen($prefix) - 1;

        /* Cut long families short but keep them apart, which the name's own
           fingerprint does where the cut would have run them together. */
        if (mb_strlen($slug) > $room) {
            $slug = rtrim(mb_substr($slug, 0, $room - 7), '-').'-'.mb_substr(sha1($slug), 0, 6);
        }

        return $prefix.'-'.$slug;
    }

    /**
     * The values of the axis specifications, in the order they were given.
     *
     * @param  array<int, array<string, mixed>>  $specs
     * @return array<int, string>
     */
    public static function axisValues(array $specs): array
    {
        $values = [];

        foreach ($specs as $spec) {
            if (! self::isAxis((string) ($spec['name'] ?? ''))) {
                continue;
            }

            $value = trim((string) ($spec['value'] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    public static function isAxis(string $specName): bool
    {
        $name = self::normalise($specName);

        if ($name === '') {
            return false;
        }

        foreach (self::AXES as $axis) {
            if ($name === $axis || str_ends_with($name, ' '.$axis)) {
                return true;
            }
        }

        return false;
    }

    /* Letters, digits and single spaces, so two spellings of the same thing
       compare equal. */
    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        /* The plus is the whole difference between a Galaxy S25 and an S25+,
           so it is kept as a word rather than dropped as punctuation. */
        $value = str_replace('+', ' plus ', $value);

        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected static function slug(string $value): string
    {
        return Slug::make($value) ?: Str::slug($value);
    }
}
