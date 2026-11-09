<?php

namespace App\Services\Feeds;

use App\Models\Attribute;
use App\Models\Product;
use App\Services\Import\LaptopSsd;
use App\Support\CategoryMainCharacteristicRules;

class PrimaryFeature
{
    protected CategoryMainCharacteristicRules $rules;

    public function __construct(protected PrimaryFeatureFormatter $formatter, ?CategoryMainCharacteristicRules $rules = null,
        protected ?DescriptionMainCharacteristic $descriptions = null)
    {
        $this->rules = $rules ?? new CategoryMainCharacteristicRules;
    }

    public function label(Product $product): string
    {
        return $this->selection($product)['label'] ?? '';
    }

    /** The catalog and export consume this same proved current-pivot selection. */
    public function selection(Product $product): ?array
    {
        $profile = $this->profile((int) $product->category_id);
        if ($profile === null || ! $this->rules->applicable($product, $profile)) {
            return null;
        }
        if ($profile['kind'] === 'ssd_storage') {
            return $this->laptopSsdSelection($product, $profile);
        }

        $candidates = [];
        $hasClaim = false;
        foreach ($product->attributeValues as $value) {
            $attribute = $value->attribute;
            if ($attribute === null || ! $this->matches($attribute, $profile)) {
                continue;
            }
            $hasClaim = $hasClaim || $value->translations->contains(fn ($row) => trim((string) $row->label) !== '');
            [$validUnit, $unit] = $this->profileUnit($attribute, $profile);
            if (! $validUnit) {
                return null;
            }
            // Meta exports ka. Explicit ka evidence keeps catalog selection independent of UI locale.
            $raw = (string) ($value->translations->firstWhere('locale', 'ka')?->label ?? '');
            if ($profile['kind'] === 'cavity_volume') {
                $stated = $this->formatter->statedCavityVolume($raw, $unit);
                if ($stated !== null && $stated['low'] < 1) {
                    return null;
                }
            }
            $measurement = $this->formatter->measure($profile['kind'], $raw, $unit);
            if ($measurement === null) {
                continue;
            }
            $validLocales = true;
            foreach ($value->translations as $translation) {
                $text = trim((string) $translation->label);
                if ($text === '') {
                    continue;
                }
                $translated = $this->formatter->measure($profile['kind'], $text, $unit);
                if ($translated === null || ! $this->agrees($profile['kind'], $measurement, $translated)) {
                    $validLocales = false;
                    break;
                }
            }
            $candidates[] = ['value_id' => (int) $value->id, 'attribute_id' => (int) $attribute->id,
                'measurement' => $measurement, 'valid_locales' => $validLocales,
                'label' => $this->formatter->format($profile['kind'], $raw, $unit)];
        }
        if ($candidates === []) {
            return $hasClaim ? null : $this->descriptionSelection($product, $profile);
        }
        if ($profile['kind'] === 'suction') {
            // Pa and kPa are explicit pressure claims. Never derive pressure from air watts or motor watts.
            $pressure = array_values(array_filter($candidates, fn ($candidate) => in_array($candidate['measurement']['unit'], ['Pa', 'kPa'], true)));
            if ($pressure !== []) {
                $candidates = $pressure;
            }
        }
        if ($profile['kind'] === 'screen_cm') {
            usort($candidates, fn (array $a, array $b) => [
                $a['attribute_id'] !== 31, $a['measurement']['source_unit'] !== 'inch', $a['attribute_id'],
            ] <=> [
                $b['attribute_id'] !== 31, $b['measurement']['source_unit'] !== 'inch', $b['attribute_id'],
            ]);
        }
        $first = $candidates[0];
        foreach ($candidates as $candidate) {
            if (! $candidate['valid_locales'] || ! $this->agrees($profile['kind'], $first['measurement'], $candidate['measurement'])) {
                return null;
            }
        }

        return $this->selected($profile, $first['label'], $first['measurement'],
            array_column($candidates, 'value_id'), array_column($candidates, 'attribute_id'));
    }

    public function eagerRelations(): array
    {
        return $this->rules->eagerRelations();
    }

    public function candidateAttributeIds(int $categoryId): ?array
    {
        return $this->rules->candidateAttributeIds($categoryId);
    }

    protected function laptopSsdSelection(Product $product, array $profile): ?array
    {
        $rows = [];
        foreach ($product->attributeValues as $value) {
            $attribute = $value->attribute;
            if ($attribute === null) {
                continue;
            }
            $rows[] = ['value_id' => (int) $value->id, 'attribute_id' => (int) $value->attribute_id,
                'code' => (string) $attribute->code, 'unit' => $attribute->unit,
                'value' => (string) ($value->translations->firstWhere('locale', 'ka')?->label ?? ''),
                'translations' => $value->translations->pluck('label', 'locale')->all()];
        }
        $proof = LaptopSsd::proof($rows);
        if ($proof === null) {
            foreach ($rows as $row) {
                if (! in_array($row['attribute_id'], [10, 704, 20, 189, 3040], true)) {
                    continue;
                }
                $texts = array_filter($row['translations'], fn ($text) => trim((string) $text) !== '');
                if ($texts === []) {
                    continue;
                }
                if ($row['attribute_id'] !== 3040 || $row['code'] !== 'shida-mekhsierebis-tipi'
                    || count(array_filter($texts, fn ($text) => preg_match('/\A\s*SSD\s*\z/iu', (string) $text))) !== count($texts)) {
                    return null;
                }
            }

            return $this->descriptionSelection($product, $profile);
        }
        $claimed = array_column($proof['evidence'], 'attribute_id');
        foreach ($rows as $row) {
            if (in_array($row['attribute_id'], $claimed, true)
                && $this->formatter->measure('ssd_storage', $row['value'], $row['unit']) === null) {
                return null;
            }
        }
        $measurement = $this->formatter->measure('ssd_storage', $proof['value']);
        if ($measurement === null) {
            return null;
        }

        return $this->selected($profile, $this->formatter->format('ssd_storage', $proof['value']), $measurement,
            array_column($proof['evidence'], 'value_id'), $claimed);
    }

    protected function descriptionSelection(Product $product, array $profile): ?array
    {
        if (! isset($profile['description'])) {
            return null;
        }

        return ($this->descriptions ??= new DescriptionMainCharacteristic($this->formatter))->selection($product, $profile);
    }

    protected function selected(array $profile, string $label, array $measurement, array $valueIds, array $attributeIds): ?array
    {
        if ($label === '') {
            return null;
        }

        return ['profile_key' => (string) $profile['profile_key'], 'kind' => $profile['kind'],
            'label' => $label, 'measurement' => $measurement,
            'source_ids' => array_values(array_unique(array_filter(array_map('intval', $valueIds), fn ($id) => $id > 0))),
            'source_attribute_ids' => array_values(array_unique(array_map('intval', $attributeIds)))];
    }

    protected function profile(int $categoryId): ?array
    {
        return $this->rules->profile($categoryId);
    }

    protected function matches(Attribute $attribute, array $profile): bool
    {
        return $this->rules->matches($attribute, $profile);
    }

    protected function profileUnit(Attribute $attribute, array $profile): array
    {
        return $this->rules->unit($attribute, $profile, $this->formatter);
    }

    protected function agrees(string $kind, array $first, array $other): bool
    {
        return $this->formatter->equivalent($kind, $first, $other);
    }
}
