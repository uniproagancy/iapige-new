<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Services\Feeds\PrimaryFeatureFormatter;

/** The shared category-to-property definition for the catalog and export. */
class CategoryMainCharacteristicRules
{
    protected ?array $resolved = null;

    public function __construct(protected ?array $definitions = null, protected ?array $parents = null) {}

    public function profile(int $categoryId): ?array
    {
        if ($this->resolved === null) {
            $roots = [];
            foreach ($this->definitions ?? config('catalog_features.profiles', []) as $key => $profile) {
                $profile['profile_key'] = (string) $key;
                $profile['caption'] = array_replace($this->captionFor((string) $key, $profile['kind']), $profile['caption'] ?? []);
                $description = config('catalog_features.description_rules.'.$key);
                if (is_array($description)) {
                    $profile['description'] = $description;
                }
                foreach ($profile['categories'] as $id) {
                    $roots[(int) $id] = $profile;
                }
            }
            $parents = $this->parents ?? Category::query()->pluck('parent_id', 'id')->all();
            $this->resolved = [];
            foreach (array_unique([...array_keys($parents), ...array_keys($roots)]) as $id) {
                $current = (int) $id;
                $seen = [];
                while ($current > 0 && ! isset($seen[$current])) {
                    if (isset($roots[$current])) {
                        if ($current === (int) $id || ($roots[$current]['inherit'] ?? true)) {
                            $this->resolved[(int) $id] = $roots[$current];
                        }
                        break;
                    }
                    $seen[$current] = true;
                    $current = (int) ($parents[$current] ?? 0);
                }
            }
        }

        return $this->resolved[$categoryId] ?? null;
    }

    /** Each priority is one semantic property; aliases within it must agree. */
    public function priorities(int $categoryId): array
    {
        $profile = $this->profile($categoryId);
        if ($profile === null) {
            return [];
        }

        return $profile['priorities'] ?? [$profile];
    }

    public function attributePriority(Attribute $attribute, int $categoryId): ?int
    {
        foreach ($this->priorities($categoryId) as $index => $profile) {
            if ($this->matches($attribute, $profile)) {
                return $index;
            }
        }

        return null;
    }

    public function eagerRelations(): array
    {
        return ['translations', 'attributeValues.translations', 'attributeValues.attribute.translations'];
    }

    public function caption(int $categoryId, string $locale = 'ka'): ?string
    {
        $profile = $this->profile($categoryId);

        return $profile === null ? null : ($profile['caption'][$locale] ?? $profile['caption']['ka']);
    }

    /** Null means legacy name aliases need all current pivots, not an empty set. */
    public function candidateAttributeIds(int $categoryId): ?array
    {
        $profile = $this->profile($categoryId);
        if ($profile === null) {
            return [];
        }
        if ($profile['kind'] === 'ssd_storage') {
            return [10, 704, 20, 189, 3040];
        }

        if (! isset($profile['attribute_ids'])) {
            return null;
        }
        $ids = $profile['attribute_ids'];
        if (isset($profile['requires_in_categories'][$categoryId])) {
            $ids[] = $profile['requires_in_categories'][$categoryId]['attribute_id'];
        }

        return array_values(array_unique($ids));
    }

    /** Mixed parent shelves require explicit current subtype evidence. */
    public function applicable(Product $product, array $profile): bool
    {
        $guard = $profile['requires_in_categories'][(int) $product->category_id] ?? null;
        if ($guard === null) {
            return true;
        }
        $allowed = array_map(fn (string $value) => $this->key($value), $guard['values']);
        $found = false;
        foreach ($product->attributeValues as $value) {
            if ((int) $value->attribute_id !== (int) $guard['attribute_id']) {
                continue;
            }
            $attribute = $value->attribute;
            if ($attribute === null || (string) $attribute->code !== $guard['code']) {
                return false;
            }
            $ka = $value->translations->firstWhere('locale', 'ka');
            if ($ka === null || ! in_array($this->key((string) $ka->label), $allowed, true)) {
                return false;
            }
            foreach ($value->translations as $translation) {
                if (trim((string) $translation->label) !== '' && ! in_array($this->key((string) $translation->label), $allowed, true)) {
                    return false;
                }
            }
            $found = true;
        }

        return $found;
    }

    public function matches(Attribute $attribute, array $profile): bool
    {
        if (isset($profile['attribute_ids']) && ! in_array((int) $attribute->id, $profile['attribute_ids'], true)) {
            return false;
        }
        $keys = [$this->key((string) $attribute->code)];
        if (! ($profile['strict_codes'] ?? false)) {
            $keys[] = $this->key((string) ($attribute->translations->firstWhere('locale', 'ka')?->name ?? ''));
        }
        foreach ($profile['exclude'] ?? [] as $alias) {
            if (in_array($this->key($alias), $keys, true)) {
                return false;
            }
        }
        foreach ($profile['attributes'] ?? [] as $alias) {
            if (in_array($this->key($alias), $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** Read a declared attribute unit, or a narrowly audited unit-bearing header. */
    public function unit(Attribute $attribute, array $profile, PrimaryFeatureFormatter $formatter): array
    {
        if (! in_array((int) $attribute->id, $profile['unit_from_name_ids'] ?? [], true)) {
            return [true, $attribute->unit];
        }
        $ka = $attribute->translations->firstWhere('locale', 'ka');
        if ($ka === null || ! preg_match('/\(([^()]*)\)\s*\z/u', (string) $ka->name, $match)) {
            return [false, null];
        }
        $declared = $formatter->measure($profile['kind'], '1', $match[1]);
        if ($declared === null) {
            return [false, null];
        }
        $expected = $declared['source_unit'];
        foreach ($attribute->translations as $translation) {
            if (preg_match('/\(([^()]*)\)\s*\z/u', (string) $translation->name, $other)) {
                $measurement = $formatter->measure($profile['kind'], '1', $other[1]);
                if ($measurement === null || $measurement['source_unit'] !== $expected) {
                    return [false, null];
                }
            }
        }
        if ($attribute->unit !== null && trim((string) $attribute->unit) !== '') {
            $measurement = $formatter->measure($profile['kind'], '1', $attribute->unit);
            if ($measurement === null || $measurement['source_unit'] !== $expected) {
                return [false, null];
            }
        }

        return [true, $expected];
    }

    protected function key(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    protected function captionFor(string $key, string $kind): array
    {
        $names = match ($key) {
            'refrigerators' => ['მთლიანი მოცულობა', 'Total capacity'],
            'electric_storage_water_heaters' => ['ავზის მოცულობა', 'Tank capacity'],
            'christmas_trees' => ['სიმაღლე', 'Height'],
            'coffee_pump_pressure' => ['ტუმბოს წნევა', 'Pump pressure'],
            'generator_output' => ['გამომავალი სიმძლავრე', 'Output power'],
            'transport_motor_power' => ['ძრავის სიმძლავრე', 'Motor power'],
            default => match ($kind) {
                'screen_cm', 'screen_inch' => ['ეკრანის დიაგონალი', 'Screen diagonal'],
                'storage', 'rom_storage' => ['შიდა მეხსიერება', 'Storage capacity'],
                'ssd_storage' => ['SSD მოცულობა', 'SSD capacity'],
                'area' => ['მაქსიმალური ფართობი', 'Maximum coverage'],
                'volume', 'fluid_capacity' => ['მოცულობა', 'Capacity'],
                'load', 'drying_load' => ['ჩატვირთვა', 'Load capacity'],
                'cavity_volume' => ['ღუმელის მოცულობა', 'Cavity capacity'],
                'place_settings' => ['პერსონების რაოდენობა', 'Place settings'],
                'cooking_zones' => ['სახურებელი ზონები', 'Cooking zones'],
                'air_flow' => ['ჰაერის ნაკადი', 'Airflow'],
                'height' => ['სიმაღლე', 'Height'],
                'power', 'max_power' => ['სიმძლავრე', 'Power'],
                'audio_power', 'rms_power' => ['ხმის სიმძლავრე', 'Audio output'],
                'charge' => ['ბატარეის ტევადობა', 'Battery capacity'],
                'energy' => ['ბატარეის ენერგია', 'Battery energy'],
                'pressure' => ['სამუშაო წნევა', 'Working pressure'],
                'suction' => ['შეწოვის სიმძლავრე', 'Suction'],
                'torque' => ['ბრუნვის მომენტი', 'Torque'],
                'runtime' => ['მუშაობის ხანგრძლივობა', 'Operating time'],
                'flight_time' => ['მაქსიმალური ფრენის დრო', 'Maximum flight time'],
                'diameter', 'cookware_diameter' => ['დიამეტრი', 'Diameter'],
                'compatible_screen' => ['თავსებადი ეკრანის ზომა', 'Compatible screen size'],
                'furniture_dimensions' => ['პროდუქტის სრული ზომები', 'Product dimensions'],
                'recommended_age' => ['რეკომენდებული ასაკი', 'Recommended age'],
                'press_force' => ['წნევის ძალა', 'Press force'],
                'switch_count' => ['ჩამრთველების რაოდენობა', 'Switch count'],
                'product_mass' => ['შეფუთვის წონა', 'Package weight'],
                'pickup_pattern' => ['ხმის მიღების ტიპი', 'Pickup pattern'],
                'cable_length' => ['სიგრძე', 'Length'],
                'rated_load' => ['დასაშვები დატვირთვა', 'Load capacity'],
                'current' => ['დენი', 'Current'], 'voltage' => ['ძაბვა', 'Voltage'],
                'steam_rate' => ['ორთქლის მიწოდება', 'Steam output'],
                'megapixels' => ['ფოტო რეზოლუცია', 'Photo resolution'],
                'dpi' => ['DPI მგრძნობელობა', 'DPI sensitivity'],
                'piece_count' => ['ნაკრებში რაოდენობა', 'Set quantity'],
                'core_count' => ['ბირთვების რაოდენობა', 'Core count'],
                'compatibility_model' => ['თავსებადი მოდელი', 'Compatible model'],
                'laptop_fit' => ['თავსებადობა / ლეპტოპის ზომა', 'Compatibility / laptop size'],
                'battery_voltage' => ['ბატარეის ძაბვა', 'Battery voltage'],
                'platform' => ['თავსებადი პლატფორმა', 'Compatible platform'],
                'native_resolution' => ['მშობლიური გარჩევადობა', 'Native resolution'],
                'video_resolution' => ['ვიდეო რეზოლუცია', 'Video resolution'],
                'wifi_standard' => ['Wi-Fi სტანდარტი', 'Wi-Fi standard'],
                'switch_type' => ['გადამრთველის ტიპი', 'Switch type'],
                'printing_technology' => ['ბეჭდვის ტექნოლოგია', 'Printing technology'],
                'socket' => ['სოკეტი', 'Socket'],
                'strap_size' => ['სამაჯურის თავსებადობა', 'Strap compatibility'],
                default => ['მთავარი მახასიათებელი', 'Main characteristic'],
            },
        };

        return ['ka' => $names[0], 'en' => $names[1]];
    }
}
