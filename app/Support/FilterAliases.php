<?php

declare(strict_types=1);

namespace App\Support;

/** Pure display/query plans. Catalog owns exact source pairs, overlap checks and distinct product counts. */
final class FilterAliases
{
    private const UNITS = ['length' => ['mm', 'cm', 'm', 'inch'], 'frequency' => ['hz', 'khz'],
        'weight' => ['g', 'kg'], 'volume' => ['ml', 'l']];

    /** The context must be the selected category and its ancestors, never a mixed parent's descendants. */
    public static function definitions(array $categoryContext): array
    {
        $rules = [];
        $screenFamilies = (int) in_array(72, $categoryContext, true) + (int) in_array(2, $categoryContext, true)
            + (int) (array_intersect([7, 250], $categoryContext) !== []);
        if ($screenFamilies > 1) {
            return [];
        }
        if (in_array(72, $categoryContext, true)) {
            $rules[] = self::rule('spec-screen-diagonal', 'ekranis-zoma', 'ეკრანის დიაგონალი', 'Screen diagonal', 'length', 'inch', [
                31 => 'ekranis-zoma', 5294 => 'ekrani-ekranis-diagonali',
            ]);
        }
        if (in_array(2, $categoryContext, true) || array_intersect([7, 250], $categoryContext) !== []) {
            $screen = self::rule('spec-screen-diagonal', 'ekranis-zoma', 'ეკრანის დიაგონალი', 'Screen diagonal', 'length', 'inch', [
                31 => 'ekranis-zoma', 5654 => 'screen',
            ]);
            $screen['allow_pairs'] = in_array(2, $categoryContext, true);
            $rules[] = $screen;
        }
        if (array_intersect([2, 72], $categoryContext) !== []) {
            $rules[] = self::rule('spec-refresh-rate', 'ganakhlebis-sikhshire', 'ეკრანის განახლების სიხშირე', 'Screen refresh rate', 'frequency', 'hz', [
                102 => 'ganakhlebis-sikhshire', 3171 => 'ekranis-ganakhlebis-sikhshire',
            ], false);
        }
        if (in_array(85, $categoryContext, true)) {
            $rules[] = self::rule('spec-drying-load', 'maksimaluri-chatvirtva', 'შრობის ტევადობა', 'Drying capacity', 'weight', 'kg', [
                756 => 'maksimaluri-datvirtva', 3805 => 'maksimaluri-chatvirtva', 6737 => 'maksimaluri-chatvirtva-shroba',
            ]);
        }
        if (array_intersect([94, 114], $categoryContext) !== []) {
            $rules[] = self::rule('spec-oven-capacity', 'ghumelis-motsuloba', 'ღუმელის მოცულობა', 'Oven capacity', 'volume', 'l', [
                3793 => 'ghumelis-motsuloba', 265 => 'motsuloba',
            ]);
        }
        if (array_intersect([97, 230], $categoryContext) !== []) {
            $rules[] = self::rule('spec-microwave-capacity', 'ghumelis-motsuloba', 'მიკროტალღური ღუმელის მოცულობა', 'Microwave capacity', 'volume', 'l', [
                3793 => 'ghumelis-motsuloba', 265 => 'motsuloba', 128 => 'shida-motsuloba',
            ]);
        }
        if (in_array(231, $categoryContext, true)) {
            $rules[] = self::rule('spec-kettle-capacity', 'motsuloba', 'წყლის მოცულობა', 'Water capacity', 'volume', 'l', [
                265 => 'motsuloba', 239 => 'tsqlis-avzis-motsuloba',
            ]);
        }

        return $rules;
    }

    /** DTO values include every current value, with FilterMeasure inspection, before UI option pruning. */
    public static function plans(array $categoryContext, array $attributes): array
    {
        return self::evaluate($categoryContext, $attributes)['plans'];
    }

    public static function diagnostics(array $categoryContext, array $attributes): array
    {
        return self::evaluate($categoryContext, $attributes)['diagnostics'];
    }

    private static function rule(string $key, string $primary, string $ka, string $en, string $dimension, string $unit, array $attributes, bool $scalar = true): array
    {
        $sources = [];
        foreach ($attributes as $id => $code) {
            $sources[] = ['id' => $id, 'code' => $code];
        }

        return ['key' => $key, 'primary_key' => $primary, 'name_ka' => $ka, 'name_en' => $en,
            'dimension' => $dimension, 'preferred_unit' => $unit, 'scalar' => $scalar, 'allow_pairs' => false, 'attributes' => $sources];
    }

    private static function evaluate(array $context, array $attributes): array
    {
        $byCode = [];
        foreach ($attributes as $attribute) {
            if (is_array($attribute) && is_string($attribute['code'] ?? null)) {
                $byCode[$attribute['code']][] = $attribute;
            }
        }
        $plans = [];
        $diagnostics = [];
        foreach (self::definitions($context) as $definition) {
            $members = [];
            $failure = null;
            foreach ($definition['attributes'] as $source) {
                $matches = $byCode[$source['code']] ?? [];
                if ($matches === []) {
                    continue;
                }
                if (count($matches) !== 1 || (int) ($matches[0]['id'] ?? $matches[0]['attribute_id'] ?? 0) !== $source['id']) {
                    $failure = 'identity_mismatch';
                    break;
                }
                $attribute = $matches[0];
                if (($attribute['filterable'] ?? true) !== true) {
                    continue;
                }
                $eligible = 0;
                $values = $attribute['values'] ?? [];
                if (! is_array($values)) {
                    $failure = 'untyped_value';
                    break;
                }
                foreach ($values as $value) {
                    if (! is_array($value)) {
                        $failure = 'untyped_value';
                        break 2;
                    }
                    if (($value['reject'] ?? false) === true) {
                        continue;
                    }
                    $reading = $value['reading'] ?? null;
                    if (! is_array($reading)) {
                        $failure = 'untyped_value';
                        break 2;
                    }
                    if (($reading['dimension'] ?? null) !== $definition['dimension']
                        || ! in_array($reading['unit'] ?? null, self::UNITS[$definition['dimension']], true)) {
                        $failure = 'wrong_dimension';
                        break 2;
                    }
                    $low = $reading['low'] ?? null;
                    $high = $reading['high'] ?? null;
                    if (! is_numeric($low) || ! is_finite((float) $low) || (float) $low <= 0
                        || ($high !== null && (! is_numeric($high) || ! is_finite((float) $high) || (float) $high < (float) $low))
                        || ($definition['scalar'] && $high !== null)) {
                        $failure = 'invalid_quantity';
                        break 2;
                    }
                    if (isset($reading['parts']) && ! self::validPair($reading, $definition)) {
                        $failure = 'invalid_quantity';
                        break 2;
                    }
                    $eligible++;
                }
                if ($eligible > 0) {
                    $members[] = $source;
                }
            }
            if ($failure === null && count($members) < 2) {
                $failure = 'missing_sources';
            }
            if ($failure !== null) {
                $diagnostics[] = ['key' => $definition['key'], 'reason' => $failure,
                    'attribute_codes' => array_column($definition['attributes'], 'code')];

                continue;
            }
            $plans[] = array_replace($definition, ['attributes' => $members,
                'attribute_ids' => array_column($members, 'id'), 'attribute_codes' => array_column($members, 'code')]);
        }

        return ['plans' => $plans, 'diagnostics' => $diagnostics];
    }

    private static function validPair(array $reading, array $definition): bool
    {
        $parts = $reading['parts'];
        if (! ($definition['allow_pairs'] ?? false) || ! is_array($parts) || count($parts) !== 2 || $reading['high'] !== null) {
            return false;
        }
        foreach ($parts as $part) {
            if (! is_array($part) || isset($part['parts']) || ($part['dimension'] ?? null) !== 'length'
                || ! in_array($part['unit'] ?? null, self::UNITS['length'], true) || ($part['high'] ?? null) !== null
                || ! is_numeric($part['low'] ?? null) || ! is_finite((float) $part['low']) || (float) $part['low'] <= 0) {
                return false;
            }
        }

        return abs((float) $reading['low'] - max(array_column($parts, 'low'))) < 0.000001;
    }
}
