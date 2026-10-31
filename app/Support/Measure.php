<?php

namespace App\Support;

/*
 | One size, written by three suppliers, arrives as 32", as 32 inches and as
 | 81 სმ -- and the shelf then offers three boxes for one television. Here a
 | reading is understood rather than compared letter by letter: the figure, the
 | unit it was written in, and the same quantity ready to be written out in
 | whichever unit the shelf itself mostly speaks.
 |
 | Only a label that is nothing but a reading is understood. "1920x1080 FHD"
 | and "6.20 inches, 3.40 inches" are left exactly as they came, because
 | guessing at half a sentence is how a filter starts lying.
 */
class Measure
{
    /*
     | Units, by the quantity they measure, each with what it takes to reach
     | the smallest of them and the spellings suppliers use. BTU is a quantity
     | of its own on purpose: a figure in BTU sits in the same specification as
     | a figure in watts without meaning the same thing, and turning one into
     | the other quietly would be an invention rather than a reading.
     */
    public const UNITS = [
        'length' => [
            'mm' => ['factor' => 1, 'words' => ['mm', 'მმ']],
            'cm' => ['factor' => 10, 'words' => ['cm', 'სმ', 'სანტიმეტრი']],
            'm' => ['factor' => 1000, 'words' => ['m', 'მ', 'მეტრი']],
            'inch' => ['factor' => 25.4, 'words' => ['"', "''", '”', '″', 'inch', 'inches', 'in', 'ინჩი', 'ინჩ']],
        ],
        'volume' => [
            'ml' => ['factor' => 1, 'words' => ['ml', 'მლ']],
            'l' => ['factor' => 1000, 'words' => ['l', 'ლ', 'ლიტრი', 'ლტ', 'liter', 'litre', 'liters', 'litres']],
        ],
        'weight' => [
            'g' => ['factor' => 1, 'words' => ['g', 'gr', 'გრ', 'გ', 'გრამი']],
            'kg' => ['factor' => 1000, 'words' => ['kg', 'კგ', 'კილოგრამი']],
        ],
        'power' => [
            'w' => ['factor' => 1, 'words' => ['w', 'watt', 'watts', 'ვტ', 'ვატი', 'ვატ']],
            'kw' => ['factor' => 1000, 'words' => ['kw', 'კვტ', 'კილოვატი']],
        ],
        'heat' => [
            'btu' => ['factor' => 1, 'words' => ['btu']],
        ],
        'memory' => [
            'mb' => ['factor' => 1, 'words' => ['mb', 'მბ']],
            'gb' => ['factor' => 1024, 'words' => ['gb', 'გბ']],
            'tb' => ['factor' => 1048576, 'words' => ['tb', 'ტბ']],
        ],
        'area' => [
            'sqm' => ['factor' => 1, 'words' => ['m²', 'მ²', 'm2', 'მ2', 'sqm', 'კვ.მ', 'კვმ']],
        ],
        'frequency' => [
            'hz' => ['factor' => 1, 'words' => ['hz', 'ჰც', 'ჰერცი']],
            'khz' => ['factor' => 1000, 'words' => ['khz', 'კჰც']],
        ],
        'voltage' => [
            'v' => ['factor' => 1, 'words' => ['v', 'ვ', 'ვოლტი', 'volt', 'volts']],
        ],
        'sound' => [
            'db' => ['factor' => 1, 'words' => ['db', 'დბ', 'დეციბელი']],
        ],
        /* A guarantee is given in months by one supplier and in years by the
           next, and three years is two years longer than one. */
        'time' => [
            'month' => ['factor' => 1, 'words' => ['თვე', 'თვის', 'month', 'months', 'mo']],
            'year' => ['factor' => 12, 'words' => ['წელი', 'წლის', 'წელიწადი', 'year', 'years', 'yr']],
        ],
    ];

    /*
     | Quantities whose unit is never exchanged for another. A gigabyte is a
     | thousand megabytes and a terabyte a thousand gigabytes, but nobody asks
     | for a 1024 GB telephone, so memory is left in whichever of them it was
     | written -- the unit only has to be spelt the one way.
     */
    protected const FIXED = ['memory'];

    /* A reading given to the nearest tenth, and read as a whole number when it
       lands this near one: 81 სმ is 31.89 inches, which is a 32" screen. */
    protected const SNAP = 0.15;

    /* How much of a shelf's answers must already be readings before a bare
       figure is taken to be one, and how far outside their sizes it may
       fall and still be read as the same kind of thing. */
    protected const CONTEXT = 0.5;

    protected const LEEWAY = 3;

    /* Units a product name uses for something other than a measure: "5G"
       is a network and "2 in 1" a kind of product. */
    protected const NOT_IN_NAMES = ['g', 'in'];

    /* Band widths worth offering, narrowest first. Every one of them is a
       figure somebody would name on their own. */
    protected const LADDER = [
        0.1, 0.25, 0.5, 1, 2, 5, 10, 20, 25, 50,
        100, 200, 250, 500, 1000, 2000, 5000, 10000, 20000, 50000,
        100000, 200000, 500000, 1000000,
    ];

    protected static ?array $index = null;

    /**
     * What a label says, or null when it says anything besides a reading.
     *
     * @return array{dimension: string, unit: string, low: float, high: ?float}|null
     */
    public static function read(?string $label): ?array
    {
        $label = Text::tidy($label);

        if ($label === '') {
            return null;
        }

        if ($reading = self::whole($label)) {
            return $reading;
        }

        /* "81(32") სმ/ინჩი" -- one screen given twice over, once in each unit.
           The reading in brackets is a reading like any other. */
        if (preg_match('/\(([^)]+)\)/u', $label, $inside)) {
            if ($reading = self::whole(trim($inside[1]))) {
                return $reading;
            }
        }

        return self::leading($label);
    }

    /** @return array{dimension: string, unit: string, low: float, high: ?float}|null */
    protected static function whole(string $label): ?array
    {
        $units = self::pattern();

        $matched = preg_match(
            '/^(\d+(?:[.,]\d+)?)\s*(?:(?:-|–|—|\/|to)\s*(\d+(?:[.,]\d+)?))?\s*('.$units.')(?:\s*(?:'.$units.'))*$/u',
            $label,
            $parts,
        );

        return $matched ? self::reading($parts[3], $parts[1], $parts[2] ?? '') : null;
    }

    /*
     | Memory arrives with its kind attached -- "16GB DDR5", "1x 16GB SODIMM
     | DDR5-5600" -- and the kind answers a different question from the size.
     | Nothing else is read out of the middle of a sentence: a reading standing
     | among other words usually belongs to one part of a product rather than
     | to the product.
     */
    protected static function leading(string $label): ?array
    {
        $matched = preg_match('/(\d+(?:[.,]\d+)?)\s*('.self::pattern().')(?![\p{L}\p{N}])/u', $label, $parts);

        if (! $matched) {
            return null;
        }

        $reading = self::reading($parts[2], $parts[1], '');

        return $reading && $reading['dimension'] === 'memory' ? $reading : null;
    }

    /** @return array{dimension: string, unit: string, low: float, high: ?float}|null */
    protected static function reading(string $word, string $low, string $high): ?array
    {
        [$dimension, $unit] = self::index()[$word];
        $factor = self::UNITS[$dimension][$unit]['factor'];

        $from = self::figure($low) * $factor;
        $to = $high === '' ? null : self::figure($high) * $factor;

        /* "9/5 კგ" is a washing machine that washes nine and dries five, not a
           range from nine down to five. A second figure below the first is
           two readings, so the label is left to speak for itself. */
        if ($to !== null && $to <= $from) {
            return null;
        }

        return [
            'dimension' => $dimension,
            'unit' => $unit,
            'low' => $from,
            'high' => $to,
        ];
    }

    protected static function pattern(): string
    {
        return implode('|', array_map(
            fn (string $word) => preg_quote($word, '/'),
            array_keys(self::index()),
        ));
    }

    /**
     * The unit each quantity is mostly written in here, which is the one the
     * rest are brought over to. Weighted by how many products carry each
     * answer, so one stray reading never renames the shelf.
     *
     * @param  array<string, array<string, mixed>|null>  $readings  answer code => reading
     * @param  array<string, int>  $held  answer code => products giving it
     * @return array<string, string> quantity => unit
     */
    public static function prevailing(array $readings, array $held = []): array
    {
        $weight = [];

        foreach ($readings as $code => $reading) {
            if (! $reading || in_array($reading['dimension'], self::FIXED, true)) {
                continue;
            }

            $weight[$reading['dimension']][$reading['unit']] =
                ($weight[$reading['dimension']][$reading['unit']] ?? 0)
                + max(1, (int) ($held[$code] ?? 0));
        }

        return array_map(function (array $units) {
            arsort($units);

            return (string) array_key_first($units);
        }, $weight);
    }

    /**
     * The same reading written out in the given unit.
     *
     * @param  array{dimension: string, unit: string, low: float, high: ?float}  $reading
     */
    public static function canonical(array $reading, ?string $unit = null): string
    {
        $unit ??= $reading['unit'];

        if (! isset(self::UNITS[$reading['dimension']][$unit])
            || in_array($reading['dimension'], self::FIXED, true)) {
            $unit = $reading['unit'];
        }

        $factor = self::UNITS[$reading['dimension']][$unit]['factor'];

        $figure = self::write($reading['low'] / $factor)
            .($reading['high'] !== null ? '-'.self::write($reading['high'] / $factor) : '');

        $written = __("unit.{$unit}");

        /* An inch mark belongs against the figure; a word does not. */
        return str_starts_with($written, '"') || $written === '"'
            ? $figure.$written
            : $figure.' '.$written;
    }

    /*
     | Readings written somewhere in a sentence -- a product's name, mostly:
     | "Samsung RB34 340L", "LG 55\" OLED". Unlike read(), which takes a label
     | only when it is nothing but a reading, this looks between the words.
     |
     | A one-letter unit that a name uses for something else is not looked
     | for: "Redmi 12 5G" is a telephone on the fifth generation of network,
     | not five grams of one.
     |
     | @return array<int, array{dimension: string, unit: string, low: float, high: ?float}>
     */
    public static function mentioned(?string $text): array
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)?)\s*('.self::pattern().')(?![\p{L}\p{N}])/u',
            Text::tidy($text),
            $found,
            PREG_SET_ORDER,
        );

        $readings = [];

        foreach ($found as [, $figure, $word]) {
            if (in_array($word, self::NOT_IN_NAMES, true)) {
                continue;
            }

            if ($reading = self::reading($word, $figure, '')) {
                $readings[] = $reading;
            }
        }

        return $readings;
    }

    /*
     | A bare figure among readings. One supplier writes "411 ლ", the next
     | writes "515" in the same place, and both mean litres -- but "515" alone
     | could be anything, so it is read as the shelf's unit only when the
     | shelf leaves no doubt: most answers are readings, all of one quantity,
     | and the bare figure is the size those readings are. A plate written
     | "28.8" beside plates of 255-345 მმ stays as written, because it is
     | the wrong size to be millimetres.
     |
     | @param  array<string, array<string, mixed>|null>  $readings  answer code => reading
     | @param  array<string, string>  $units  quantity => the unit the shelf speaks
     | @param  array<string, string|null>  $labels  answer code => as written
     | @return array<string, array<string, mixed>|null>
     */
    public static function understood(array $readings, array $units, array $labels): array
    {
        $known = array_filter($readings);

        if (count($known) < max(2, count($labels) * self::CONTEXT)) {
            return $readings;
        }

        $quantities = array_unique(array_column($known, 'dimension'));

        if (count($quantities) !== 1) {
            return $readings;
        }

        $dimension = (string) reset($quantities);
        $unit = $units[$dimension] ?? null;

        if ($unit === null || ! isset(self::UNITS[$dimension][$unit])) {
            return $readings;
        }

        $sizes = array_column($known, 'low');
        $low = min($sizes) / self::LEEWAY;
        $high = max($sizes) * self::LEEWAY;
        $factor = self::factor($dimension, $unit);

        foreach ($readings as $code => $reading) {
            if ($reading !== null) {
                continue;
            }

            $said = Text::tidy($labels[$code] ?? null);

            if (! preg_match('/^(\d+(?:[.,]\d+)?)$/u', $said, $figure)) {
                continue;
            }

            $size = self::figure($figure[1]) * $factor;

            if ($size >= $low && $size <= $high) {
                $readings[$code] = ['dimension' => $dimension, 'unit' => $unit, 'low' => $size, 'high' => null];
            }
        }

        return $readings;
    }

    /*
     | Whether readings of this quantity may be gathered into bands. Memory
     | may not: "0-500 GB" is nobody's idea of a telephone, while the handful
     | of sizes telephones are made in is a choice already.
     */
    public static function bandable(string $dimension): bool
    {
        return ! in_array($dimension, self::FIXED, true);
    }

    public static function factor(string $dimension, string $unit): float
    {
        return (float) (self::UNITS[$dimension][$unit]['factor'] ?? 1);
    }

    /*
     | A band width that leaves few enough bands to read: the narrowest round
     | step that does. Round, because 200-300 ლ is a size somebody thinks in
     | and 187-294 ლ is not.
     */
    public static function step(float $low, float $high, int $most): float
    {
        foreach (self::LADDER as $step) {
            if (ceil($high / $step) - floor($low / $step) <= $most) {
                return $step;
            }
        }

        return (float) self::LADDER[count(self::LADDER) - 1];
    }

    /* One band, written out: "200-300 ლ". */
    public static function span(float $from, float $to, string $dimension, string $unit): string
    {
        $factor = self::factor($dimension, $unit);

        return self::canonical([
            'dimension' => $dimension,
            'unit' => $unit,
            'low' => $from * $factor,
            'high' => $to * $factor,
        ], $unit);
    }

    protected static function write(float $value): string
    {
        $value = $value < 1 ? round($value, 2) : ($value < 10 ? round($value, 1) : round($value));

        $whole = round($value);

        if (abs($value - $whole) <= self::SNAP) {
            $value = $whole;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    protected static function figure(string $written): float
    {
        return (float) str_replace(',', '.', $written);
    }

    /* unit spelling => [quantity, unit] */
    protected static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (self::UNITS as $dimension => $units) {
            foreach ($units as $unit => $about) {
                foreach ($about['words'] as $word) {
                    $index[$word] ??= [$dimension, $unit];
                }
            }
        }

        /* Longest spelling first, so "მმ" is not read as "მ" followed by
           something the pattern then refuses. */
        uksort($index, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        self::$index = $index;

        return $index;
    }
}
