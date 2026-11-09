<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Attribute;

/** Display-only interpretation. Source values, mappings and product metadata are never changed. */
final class PreviousFilterMeasure
{
    private const TEXT_CODES = ['modeli', 'model', 'sku', 'part-number', 'brendi', 'produktis-tipi',
        'protsesori', 'protsesoris-modeli', 'protsesoris-modelis-nomeri', 'tsentraluri-protsesori',
        'protsesorischipsetis-tipi', 'protsesoris-taoba', 'grafikuli-protsesori', 'video-baratis-modeli'];

    private const MEMORY_CODES = ['operatiuli-mekhsiereba', 'ssd-motsuloba', 'ssd', 'shida-mekhsiereba',
        'video-baratis-mekhsiereba', 'mqari-diskis-motsuloba', 'mekhsierebis-tevadoba',
        'operatiuli-mekhsierebis-motsuloba', 'baratis-tevadoba', 'protsesoris-keshi', 'kesh-mekhsiereba'];

    private const SCREEN_CODES = ['ekranis-zoma', 'ekranis-diagonali', 'ekrani-ekranis-diagonali', 'screen-size', 'screen-diagonal', 'screen', 'ekrani'];

    /** @return array{reading:?array,reject:bool,allow_context:bool,reason:?string} */
    public static function inspect(Attribute $attribute, ?string $label, array $categoryIds = []): array
    {
        $empty = ['reading' => null, 'reject' => false, 'allow_context' => false, 'reason' => null];
        $text = Text::tidy($label);
        if ($text === '' || mb_strlen($text) > 1024 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
            return $empty;
        }
        $code = (string) $attribute->code;
        $whole = self::whole($text);
        if (in_array($code, self::TEXT_CODES, true)) {
            return ['reading' => null, 'reject' => ($whole['dimension'] ?? null) === 'memory',
                'allow_context' => false, 'reason' => ($whole['dimension'] ?? null) === 'memory' ? 'capacity_in_text_property' : null];
        }
        // A washing/drying pair is handled by Catalog::pairedLoad, never as a numeric range.
        if (in_array($code, ['maksimaluri-chatvirtva', 'washing-capacity', 'wash-capacity', 'retskhvis-tevadoba', 'retskhvis-motsuloba'], true)
            && preg_match('~\A[0-9.,]+\s*/\s*[0-9.,]+\s*(?:kg|კგ|კილოგრამი)\z~u', $text)) {
            return $empty;
        }
        $screen = (int) $attribute->id === 5654 || in_array($code, self::SCREEN_CODES, true)
            || ($code === 'zoma' && array_intersect($categoryIds, [2, 7, 9, 13, 72, 250, 411]) !== []);
        if ($screen) {
            $reading = $whole;
            if ($reading === null && str_contains($text, '(')) {
                [$reading, $conflict] = self::dualScreen($text);
                if ($conflict) {
                    return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'conflicting_screen_units'];
                }
            }
            if ($reading !== null && $reading['dimension'] !== 'length') {
                return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'wrong_screen_dimension'];
            }
        } elseif (in_array($code, self::MEMORY_CODES, true)) {
            if ($code === 'ssd-motsuloba') {
                $ssd = SsdCapacity::normalize($label, $attribute->unit);
                $reading = $ssd !== null ? self::whole($ssd) : null;
            } else {
                $reading = self::memory($text);
            }
            if ($whole !== null && $whole['dimension'] !== 'memory') {
                return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'wrong_memory_dimension'];
            }
        } else {
            // No substring, bracket or contextual majority can turn arbitrary prose into a measurement.
            $reading = $whole;
        }
        if ($reading === null && preg_match('~\A[0-9]+(?:[.,][0-9]+)?(?:\s*[-–—]\s*[0-9]+(?:[.,][0-9]+)?)?\z~u', $text)) {
            $unit = self::declaredUnit($attribute);
            if ($unit !== null) {
                $reading = self::whole($text.' '.$unit);
            }
        }
        if ($reading !== null && in_array($reading['dimension'], ['length', 'memory', 'area'], true) && $reading['low'] <= 0) {
            return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'nonpositive_size'];
        }
        if ($screen && $reading !== null && $reading['dimension'] === 'length') {
            $inches = $reading['low'] / Measure::factor('length', 'inch');
            if (array_intersect($categoryIds, [7, 250]) !== [] && (abs($inches - 1.3) < 0.00001 || abs($inches - 1.4) < 0.00001)) {
                return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'unverified_tiny_laptop_screen'];
            }
            $reading['screen'] = true;
            $reading['whole_inch_snap'] = in_array(72, $categoryIds, true);
        }
        if ($reading !== null && $reading['dimension'] === 'volume' && $reading['low'] < Measure::factor('volume', 'l')
            && in_array((int) $attribute->id, [128, 265, 307, 3793], true)
            && array_intersect($categoryIds, [94, 97, 114, 230]) !== []) {
            return ['reading' => null, 'reject' => true, 'allow_context' => false, 'reason' => 'unverified_tiny_cavity_volume'];
        }

        return ['reading' => $reading, 'reject' => false, 'allow_context' => false, 'reason' => null];
    }

    /** Same measurement contract as Measure::read, accepting only a complete explicit quantity. */
    private static function whole(string $text): ?array
    {
        $number = '[0-9]{1,12}(?:[.,][0-9]{1,8})?';
        $pattern = self::pattern();
        if (! preg_match('~\A(?<low>'.$number.')\s*(?<first>'.$pattern.')?(?:\s*(?:[-–—/]|to)\s*(?<high>'.$number.')\s*(?<second>'.$pattern.')?)?\z~u', Text::tidy($text), $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $first = $parts['first'] !== null ? self::unit($parts['first']) : null;
        $second = $parts['second'] !== null ? self::unit($parts['second']) : null;
        if ($first === null && $second === null) {
            return null;
        }
        $first ??= $second;
        $second ??= $first;
        if ($first[0] !== $second[0] || ($first[0] === 'memory' && $first[1] !== $second[1])) {
            return null;
        }
        $low = (float) str_replace(',', '.', $parts['low']) * Measure::factor($first[0], $first[1]);
        $high = $parts['high'] !== null ? (float) str_replace(',', '.', $parts['high']) * Measure::factor($second[0], $second[1]) : null;
        if ($high !== null && $high < $low) {
            return null;
        }

        return ['dimension' => $first[0], 'unit' => $first[1], 'low' => $low, 'high' => $high !== $low ? $high : null,
            'source_decimals' => self::decimals($parts['low']), 'high_source_decimals' => $parts['high'] !== null ? self::decimals($parts['high']) : null];
    }

    private static function memory(string $text): ?array
    {
        $whole = self::whole($text);
        if (($whole['dimension'] ?? null) === 'memory') {
            return $whole;
        }
        $words = [];
        foreach (Measure::UNITS['memory'] as $unit) {
            array_push($words, ...$unit['words']);
        }
        $pattern = implode('|', array_map(fn ($word) => preg_quote($word, '~'), $words));
        preg_match_all('~(?<![\p{L}\p{N}.,])([0-9]+(?:[.,][0-9]+)?)\s*(?:'.$pattern.')(?![\p{L}\p{N}])~u', $text, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0]) !== 1) {
            return null;
        }
        $prefix = substr($text, 0, $matches[0][0][1]);
        if (preg_match('~(?:[2-9]|[1-9][0-9]+)\s*[x×]\s*\z~u', $prefix)
            || preg_match('~\b(?:up\s+to|max(?:imum)?|supports?|supported|expandable|compatible|optional)\b|მდე|მაქსიმ|მხარდაჭერ|თავსებად~u', $prefix)) {
            return null;
        }

        return self::whole($matches[0][0][0]);
    }

    private static function dualScreen(string $text): array
    {
        if (! preg_match('~\A([^()]*)\(([^()]*)\)([^()]*)\z~u', $text, $parts)) {
            return [null, false];
        }
        $inside = self::whole(trim($parts[2]));
        $outside = self::whole(trim($parts[1].' '.$parts[3]));
        if ($outside === null && preg_match('/\A[0-9]+(?:[.,][0-9]+)?\z/u', trim($parts[1]))) {
            $units = array_map('trim', explode('/', trim($parts[3])));
            if (count($units) === 2 && $inside !== null) {
                $known = array_map(fn ($unit) => self::unit($unit), $units);
                if ($known[0] !== null && $known[1] !== null && $known[0][0] === 'length' && $known[1][0] === 'length'
                    && in_array($inside['unit'], array_column($known, 1), true)) {
                    $other = $known[0][1] === $inside['unit'] ? $units[1] : $units[0];
                    $outside = self::whole(trim($parts[1]).' '.$other);
                }
            }
        }
        if ($inside === null || $outside === null || $inside['dimension'] !== 'length' || $outside['dimension'] !== 'length') {
            return [null, false];
        }
        if ($inside['high'] !== null || $outside['high'] !== null || abs($inside['low'] - $outside['low']) > 10) {
            return [null, true];
        }

        return [$inside['unit'] === 'inch' ? $inside : $outside, false];
    }

    private static function declaredUnit(Attribute $attribute): ?string
    {
        if (trim((string) $attribute->unit) !== '') {
            return self::unit((string) $attribute->unit) !== null ? (string) $attribute->unit : null;
        }
        $translations = $attribute->translations;
        $ka = $translations->firstWhere('locale', 'ka');
        if ($ka === null || ! preg_match('~\(([^()]*)\)\s*\z~u', (string) $ka->name, $match)) {
            return null;
        }
        $unit = self::unit($match[1]);
        if ($unit === null) {
            return null;
        }
        foreach ($translations as $translation) {
            if (preg_match('~\(([^()]*)\)\s*\z~u', (string) $translation->name, $other) && self::unit($other[1]) !== $unit) {
                return null;
            }
        }

        return $match[1];
    }

    public static function canonical(array $reading, ?string $unit = null): string
    {
        [$unit, $low, $high] = self::displayValues($reading, $unit);
        $value = self::number($low).($high !== null ? '-'.self::number($high) : '');
        $written = __('unit.'.$unit);

        return $value.($written === '"' ? '' : ' ').$written;
    }

    public static function quantityKey(array $reading, ?string $unit = null): string
    {
        [$unit, $low, $high] = self::displayValues($reading, $unit);
        $number = fn ($value) => str_replace('.', 'p', self::number($value));

        return 'quantity-'.$reading['dimension'].'-'.$unit.'-'.$number($low).($high !== null ? '-to-'.$number($high) : '');
    }

    private static function number(float $value): string
    {
        $precision = $value > 0 && $value < 0.00000001 ? min(14, max(8, (int) ceil(-log10($value)) + 2)) : 8;

        return rtrim(rtrim(number_format($value, $precision, '.', ''), '0'), '.');
    }

    private static function decimals(string $number): int
    {
        $parts = preg_split('/[.,]/', $number);

        return isset($parts[1]) ? strlen($parts[1]) : 0;
    }

    private static function displayValues(array $reading, ?string $unit): array
    {
        $dimension = $reading['dimension'];
        $unit ??= $reading['unit'];
        if ($dimension === 'memory' || ! isset(Measure::UNITS[$dimension][$unit])) {
            $unit = $reading['unit'];
        }
        $factor = Measure::factor($dimension, $unit);
        $low = $reading['low'] / $factor;
        $high = $reading['high'] !== null ? $reading['high'] / $factor : null;
        if (($reading['screen'] ?? false) && $unit === 'inch' && $reading['unit'] !== 'inch') {
            if (($reading['whole_inch_snap'] ?? false) && abs($low - round($low)) <= 0.15) {
                $low = round($low);
            } elseif (! ($reading['whole_inch_snap'] ?? false) && ($reading['source_decimals'] ?? 8) <= 1) {
                $sourcePrecision = 0.5 * pow(10, -$reading['source_decimals']) * Measure::factor($dimension, $reading['unit']) / $factor;
                if (abs($low - round($low, 1)) <= $sourcePrecision + 0.000000001) {
                    $low = round($low, 1);
                }
            }
            if ($high !== null && ($reading['whole_inch_snap'] ?? false) && abs($high - round($high)) <= 0.15) {
                $high = round($high);
            } elseif ($high !== null && ! ($reading['whole_inch_snap'] ?? false) && ($reading['high_source_decimals'] ?? 8) <= 1) {
                $sourcePrecision = 0.5 * pow(10, -$reading['high_source_decimals']) * Measure::factor($dimension, $reading['unit']) / $factor;
                if (abs($high - round($high, 1)) <= $sourcePrecision + 0.000000001) {
                    $high = round($high, 1);
                }
            }
        }

        return [$unit, $low, $high];
    }

    private static function unit(string $word): ?array
    {
        $word = Text::tidy($word);
        foreach (Measure::UNITS as $dimension => $units) {
            foreach ($units as $unit => $metadata) {
                if (in_array($word, $metadata['words'], true)) {
                    return [$dimension, $unit];
                }
            }
        }

        return null;
    }

    private static function pattern(): string
    {
        $words = [];
        foreach (Measure::UNITS as $units) {
            foreach ($units as $metadata) {
                array_push($words, ...$metadata['words']);
            }
        }
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn ($word) => preg_quote($word, '~'), array_unique($words)));
    }
}
