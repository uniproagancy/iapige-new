<?php

namespace App\Support;

/*
 | Colour is the one thing nearly every product says something about, and every
 | supplier says it differently: "Black", "შავი", "Space Black", "Black/Red",
 | "Jet Black". Offered one by one that is 358 boxes to read on the telephone
 | shelf alone, which is a list rather than a choice.
 |
 | Here each of them is read back to the colour it is a shade of, so the shelf
 | offers a dozen colours a person actually thinks in. The product keeps the
 | name its supplier gave it -- the shade is what the picture shows and what
 | the colour picker switches between; only the filter speaks in base colours.
 |
 | A name saying two colours is filed under the first of them: somebody looking
 | for a black case will accept "Black/Orange", while somebody looking for
 | orange is not thinking of a black one with an orange edge.
 */
class Palette
{
    /*
     | Base colours, and the words that name them. A word earns its place here
     | only when it means one colour and nothing else: "steel", "ice" and
     | "stone" are left out because they name products too, and a shade left
     | unrecognised simply keeps its own name.
     |
     | Modifiers -- dark, light, matt, deep, space, arctic -- are deliberately
     | absent: they sit beside the colour word, which is found anyway.
     */
    public const COLOURS = [
        'black' => [
            'black', 'onyx', 'obsidian', 'jet', 'ebony', 'midnight', 'noir',
            'carbon',
            'შავი', 'შავ',
        ],
        'white' => [
            'white', 'snow', 'starlight', 'moonlight', 'porcelain',
            'თეთრი', 'თეთრ', 'თოვლისფერი',
        ],
        'grey' => [
            'grey', 'gray', 'graphite', 'charcoal', 'slate', 'titanium',
            'titan', 'anthracite', 'gunmetal', 'smoke', 'smoky',
            'ნაცრისფერი', 'ნაცრისფერ', 'ანტრაციტი', 'გრაფიტი',
            'ნახშირისფერი',
        ],
        'silver' => [
            'silver', 'platinum', 'inox', 'chrome',
            'ვერცხლისფერი', 'ვერცხლისფერ', 'ფოლად', 'პლატინა', 'ინოქს',
        ],
        'gold' => [
            'gold', 'golden', 'bronze', 'brass',
            'ოქროსფერი', 'ოქროსფერ', 'ბრონზ',
        ],
        'blue' => [
            'blue', 'navy', 'cyan', 'azure', 'sapphire', 'indigo',
            'ultramarine', 'aqua', 'turquoise', 'teal', 'denim',
            'ლურჯი', 'ლურჯ', 'ცისფერი', 'ცისფერ', 'ზღვისფერი',
        ],
        'green' => [
            'green', 'olive', 'mint', 'lime', 'emerald', 'jade', 'khaki',
            'sage',
            'მწვანე', 'ზეთისხილისფერი',
        ],
        'red' => [
            'red', 'burgundy', 'crimson', 'scarlet', 'ruby', 'wine', 'maroon',
            'berry',
            'წითელი', 'წითელ', 'შინდისფერი', 'შინდისფერ', 'ალისფერი',
        ],
        'pink' => [
            'pink', 'rose', 'coral', 'magenta', 'fuchsia', 'peach', 'salmon',
            'blush',
            'ვარდისფერი', 'ვარდისფერ', 'ატმისფერი', 'ფუქსინ', 'მაგენტ',
        ],
        'purple' => [
            'purple', 'violet', 'lilac', 'lavender', 'lavander', 'mauve',
            'plum',
            'იასამნისფერი', 'იისფერი', 'იისპერი', 'მეწამული',
        ],
        'yellow' => [
            'yellow', 'amber', 'mustard', 'lemon',
            'ყვითელი', 'ყვითელ',
        ],
        'orange' => [
            'orange', 'tangerine', 'apricot',
            'ნარინჯისფერი', 'ნარინჯისფერ', 'ფორთოხლისფერი',
        ],
        'brown' => [
            'brown', 'mocha', 'taupe', 'chocolate', 'coffee', 'walnut',
            'wood', 'copper', 'terracotta', 'tan',
            'ყავისფერი', 'ყავისფერ', 'ხისფერი', 'ხისფერ', 'აგურისფერი',
            'სპილენძისფერი',
        ],
        'beige' => [
            'beige', 'cream', 'ivory', 'sand', 'champagne', 'nude',
            'კრემისფერი', 'კრემისფერ', 'ქვიშისფერი', 'ბეჟი',
        ],
        'transparent' => [
            'transparent', 'transparant', 'clear', 'crystal',
            'გამჭვირვალე', 'გამჭირვალე', 'უფერული',
        ],
    ];

    /*
     | Shades whose first word would otherwise answer for them. "Rose Gold" is
     | a gold and "Mint Green" a green, so the pair is read before the words
     | are taken one at a time.
     */
    protected const COMPOUNDS = [
        'rose gold' => 'gold',
        'pink gold' => 'gold',
        'sand gold' => 'gold',
        'mint green' => 'green',
        'olive green' => 'green',
        'terra cotta' => 'brown',
        'coral blue' => 'blue',
        'coral red' => 'red',
        'peach blue' => 'blue',
    ];

    /* The longer Georgian words are matched by their stem, because Georgian
       gives a colour an ending of its own -- "ფოლადისგან" for steel. A latin
       word is matched whole: "red" must not answer for "Redmi". */
    protected const STEM_LENGTH = 5;

    protected static ?array $index = null;

    /*
     | Which base colour a written shade is one of, or null when nothing in it
     | names a colour -- a shade called "Tinsel Lucent" is nobody's idea of a
     | filter and is better left out than filed under a guess.
     */
    public static function baseOf(?string $shade): ?string
    {
        /* Letters only, so that everything separating two colours --
           slashes, commas, dashes -- leaves each of them readable. */
        $shade = Text::words($shade, '\p{L}');

        if ($shade === '') {
            return null;
        }

        foreach (self::COMPOUNDS as $pair => $base) {
            if (str_contains($shade, $pair)) {
                return $base;
            }
        }

        foreach (preg_split('/\s+/u', $shade) ?: [] as $word) {
            if ($base = self::wordFor($word)) {
                return $base;
            }
        }

        return null;
    }

    public static function label(string $base): string
    {
        return __("colour.{$base}");
    }

    /* @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::COLOURS);
    }

    protected static function wordFor(string $word): ?string
    {
        self::$index ??= self::buildIndex();

        if (isset(self::$index[$word])) {
            return self::$index[$word];
        }

        if (! preg_match('/\p{Georgian}/u', $word)) {
            return null;
        }

        foreach (self::$index as $known => $base) {
            if (mb_strlen((string) $known) >= self::STEM_LENGTH && str_starts_with($word, (string) $known)) {
                return $base;
            }
        }

        return null;
    }

    /* @return array<string, string> */
    protected static function buildIndex(): array
    {
        $index = [];

        foreach (self::COLOURS as $base => $words) {
            foreach ($words as $word) {
                $index[$word] ??= $base;
            }
        }

        /* Longest first, so a shorter stem that happens to begin a longer word
           does not answer for it. */
        uksort($index, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        return $index;
    }
}
