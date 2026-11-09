<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Product;
use App\Support\CategoryTree;
use App\Support\SsdCapacity;

/** Read current filter evidence; descriptions, titles and historical specs are excluded. */
final class LaptopSsd
{
    public const ATTRIBUTE_ID = 10;

    public const ATTRIBUTE_CODE = 'ssd-motsuloba';

    public const CATEGORY_IDS = [7, 250];

    private const CODES = [10 => 'ssd-motsuloba', 704 => 'ssd', 20 => 'mqari-diski',
        189 => 'shida-mekhsiereba', 3040 => 'shida-mekhsierebis-tipi'];

    public static function supports(?int $categoryId): bool
    {
        return $categoryId !== null && (in_array($categoryId, self::CATEGORY_IDS, true)
            || array_intersect(CategoryTree::ancestorIds($categoryId), self::CATEGORY_IDS) !== []);
    }

    public static function proof(array $filters): ?array
    {
        $claims = [];
        $generic = [];
        $types = [];
        foreach ($filters as $row) {
            $id = (int) ($row['attribute_id'] ?? 0);
            if (! isset(self::CODES[$id]) || ($row['code'] ?? '') !== self::CODES[$id]) {
                continue;
            }
            if ($id === 3040) {
                $texts = $row['translations'] ?? [];
                $types[] = isset($texts['ka']) && $texts !== []
                    && count(array_filter($texts, fn ($text) => preg_match('/\A\s*SSD\s*\z/iu', (string) $text) === 1)) === count($texts);

                continue;
            }
            if ($id === 189) {
                $generic[] = $row;

                continue;
            }
            $read = self::readRow($row, $id === 20);
            if ($read === null) {
                return null;
            }
            $claims[] = $read + ['canonical' => $id === self::ATTRIBUTE_ID];
        }
        if ($types !== [] && ! in_array(false, $types, true)) {
            foreach ($generic as $row) {
                // Generic memory is capacity only after explicit SSD typing,
                // and still has to be a whole quantity, never a RAM annotation.
                if (! preg_match('/\A\s*[0-9]{1,9}(?:[.,][0-9]{1,3})?\s*(?:GB|TB|გბ|ტბ)\s*\z/iu', (string) ($row['value'] ?? ''))) {
                    return null;
                }
                foreach ($row['translations'] ?? [] as $text) {
                    if (! preg_match('/\A\s*[0-9]{1,9}(?:[.,][0-9]{1,3})?\s*(?:GB|TB|გბ|ტბ)\s*\z/iu', (string) $text)) {
                        return null;
                    }
                }
                $read = self::readRow($row);
                if ($read === null) {
                    return null;
                }
                $claims[] = $read + ['canonical' => false];
            }
        }
        $values = array_values(array_unique(array_column($claims, 'value')));
        if (count($values) !== 1) {
            return null;
        }
        $translations = [];
        $canonicalIds = [];
        foreach ($claims as $claim) {
            foreach (array_keys($claim['translations']) as $locale) {
                $translations[$locale] = $values[0];
            }
            if ($claim['canonical'] && $claim['value_id'] !== null) {
                $canonicalIds[] = $claim['value_id'];
            }
        }
        ksort($translations);

        return ['value' => $values[0], 'canonical_present' => count(array_filter($claims, fn ($claim) => $claim['canonical'])) > 0,
            'canonical_value_ids' => array_values(array_unique($canonicalIds)), 'translations' => $translations, 'evidence' => $claims];
    }

    private static function readRow(array $row, bool $requiresSsd = false): ?array
    {
        $texts = $row['translations'] ?? [];
        if (! isset($texts['ka']) || trim((string) $texts['ka']) === '') {
            return null;
        }
        $value = null;
        foreach ($texts as $text) {
            if ($requiresSsd && ! preg_match('/\bSSD\b/iu', (string) $text)) {
                return null;
            }
            $normalized = SsdCapacity::normalize((string) $text, $row['unit'] ?? null);
            if ($normalized === null || ($value !== null && $value !== $normalized)) {
                return null;
            }
            $value = $normalized;
        }

        return ['attribute_id' => (int) $row['attribute_id'], 'code' => $row['code'],
            'raw' => $row['value'] ?? $texts['ka'], 'value' => $value, 'translations' => $texts,
            'value_id' => isset($row['value_id']) ? (int) $row['value_id'] : (isset($row['id']) ? (int) $row['id'] : null)];
    }

    public static function forProduct(Product $product, bool $lock = false): ?array
    {
        if (! self::supports($product->category_id !== null ? (int) $product->category_id : null)) {
            return null;
        }
        $query = $product->attributeValues()->whereIn('attribute_id', array_keys(self::CODES))
            ->with(['translations' => fn ($query) => $lock ? $query->lockForUpdate() : $query,
                'attribute' => fn ($query) => $lock ? $query->lockForUpdate() : $query]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = [];
        foreach ($query->get() as $value) {
            if ($value->attribute === null) {
                continue;
            }
            $rows[] = ['value_id' => (int) $value->id, 'attribute_id' => (int) $value->attribute_id,
                'code' => (string) $value->attribute->code, 'unit' => $value->attribute->unit,
                'value' => (string) $value->label, 'translations' => $value->translations->pluck('label', 'locale')->all()];
        }

        return self::proof($rows);
    }
}
