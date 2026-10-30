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
            'g' => ['factor' => 1, 'words' => ['g', 'gr', 'გრ', 'გრამი']],
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

    protected static ?array $index = null;

    /**
     * What a label says, or null when it says anything besides a reading.
     *
     * @return array{dimension: string, unit: string, low: float, high: ?float}|null
     */
    public static function read(?string $label): ?array
    {
        $label = self::plain($label);

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

    /** @return array{dimension: string, unit: string, low: float, high: ?float} */
    protected static function reading(string $word, string $low, string $high): array
    {
        [$dimension, $unit] = self::index()[$word];
        $factor = self::UNITS[$dimension][$unit]['factor'];

        return [
            'dimension' => $dimension,
            'unit' => $unit,
            'low' => self::figure($low) * $factor,
            'high' => $high === '' ? null : self::figure($high) * $factor,
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

    protected static function plain(?string $label): string
    {
        $label = html_entity_decode((string) $label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(mb_strtolower((string) preg_replace('/\s+/u', ' ', $label)));
    }
}
