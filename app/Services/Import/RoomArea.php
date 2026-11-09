<?php

namespace App\Services\Import;

class RoomArea
{
    public const ATTRIBUTE_CODE = 'rekomendirebuli-farti';

    public const CATEGORY_IDS = [90, 98, 99, 100, 103, 104, 405];

    public static function attributeCodes(): array
    {
        return [
            'rekomendirebuli-fartobi',
            'fartobi',
            'gatbobagagrilebis-fartobi',
            'mokmedebis-fartobi',
            self::ATTRIBUTE_CODE,
            'gasatbobi-fartobi',
            'rekomendebuli-farti',
            'uncategorized-samushao-fartobi-m2',
            'gatbobis-fartobi',
            'farti',
        ];
    }

    protected const NAMES = [
        'რეკომენდირებული ფართობი',
        'ფართობი',
        'გათბობა/გაგრილების ფართობი',
        'მოქმედების ფართობი',
        'რეკომენდირებული ფართი',
        'რეკომენდირებული ფართი (დაახლოებით)',
        'გასათბობი ფართობი',
        'რეკომენდებული ფართი',
        'სამუშაო ფართობი (მ2)',
        'გათბობის ფართობი',
        'ფართი',
    ];

    public static function matches(?int $categoryId, string $name, array $rows): bool
    {
        if (! in_array($categoryId, self::CATEGORY_IDS, true)) {
            return false;
        }

        $name = self::name($name);

        if (in_array($name, self::NAMES, true)) {
            return true;
        }

        // The supplier also uses this key for litres, so its name alone is insufficient.
        if ($name !== 'მოცულობა') {
            return false;
        }

        $found = false;

        foreach ($rows as $row) {
            $value = self::raw($row['value'] ?? null);

            if (self::isEmpty($value)) {
                continue;
            }

            if (self::normalize($value) === null) {
                return false;
            }

            $found = true;
        }

        return $found;
    }

    public static function normalizeRows(array $rows, ?string $unit = null): array
    {
        $normalized = [];
        $quantities = [];

        foreach ($rows as $row) {
            $declared = $unit !== null && trim($unit) !== '';
            $value = self::normalize(
                self::raw($row['value'] ?? null),
                $declared ? $unit : self::nameUnit((string) ($row['name'] ?? '')),
            );

            if ($value === null) {
                continue;
            }

            $quantities[$value] = true;
            $row['value'] = $value;
            $normalized[] = $row;
        }

        // A single filter option must not change quantity when the language changes.
        return count($quantities) === 1 ? $normalized : [];
    }

    public static function normalize(string $value, ?string $unit = null): ?string
    {
        if (mb_strlen($value) > 128 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';

        if (! preg_match('~\A(?<first>'.$number.')\s*(?<firstUnit>.*?)(?:\s*[-–—]\s*(?<second>'.$number.')\s*(?<secondUnit>.*?))?\z~u', $value, $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $hasUnit = false;

        foreach ([$parts['firstUnit'], $parts['secondUnit'], $unit] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            if (! self::squareMetres($candidate)) {
                return null;
            }

            $hasUnit = true;
        }

        if (! $hasUnit) {
            return null;
        }

        $first = (float) str_replace(',', '.', $parts['first']);
        $second = $parts['second'] !== null ? (float) str_replace(',', '.', $parts['second']) : null;

        if ($first <= 0 || ($second !== null && ($second <= 0 || $second < $first))) {
            return null;
        }

        $label = self::number($first);

        if ($second !== null && $second !== $first) {
            $label .= '-'.self::number($second);
        }

        return $label.' მ²';
    }

    protected static function nameUnit(string $name): ?string
    {
        return self::name($name) === 'სამუშაო ფართობი (მ2)' ? 'მ²' : null;
    }

    protected static function squareMetres(string $unit): bool
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace(['²', '^2'], '2', $unit);
        $unit = (string) preg_replace('/[\s\p{Z}.]+/u', '', $unit);

        return in_array($unit, [
            'm2', 'მ2', 'м2', 'sqm', 'კვმ',
            'squaremeter', 'squaremeters', 'squaremetre', 'squaremetres',
            'კვადრატულიმეტრი', 'კვადრატულიმეტრები',
        ], true);
    }

    protected static function name(string $name): string
    {
        $name = str_replace('²', '2', mb_strtolower($name));
        $name = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));

        return trim((string) preg_replace('/[:：]+$/u', '', $name));
    }

    protected static function isEmpty(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['', '-', '–', '—', 'n/a', 'null', 'none'], true);
    }

    protected static function raw(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    protected static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
