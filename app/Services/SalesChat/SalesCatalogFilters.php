<?php

namespace App\Services\SalesChat;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesCatalogFilters
{
    private const FILTER_LIMIT = 14;

    private const VALUE_LIMIT = 8;

    /** @var array<int, array> */
    private array $sidebar = [];

    /** Browse the public filters using only values assigned to products the shopper can buy. */
    public function choices(Builder $products, Category $category, ?SalesCatalogQuery $question = null): array
    {
        $eligible = (clone $products)->withoutEagerLoads();
        $productsOnShelf = (clone $eligible)->select('products.id')->toBase();
        $choices = DB::table('attribute_value_product as assigned')
            ->join('attribute_values as av', 'av.id', '=', 'assigned.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('a.is_filterable', true)->whereNotIn('a.code', Catalog::RESERVED_FILTERS)
            ->whereIn('assigned.product_id', $productsOnShelf)
            ->selectRaw('a.id as attribute_id, av.id as value_id, count(*) as used')
            ->groupBy('a.id', 'av.id')->orderByDesc('used')->orderBy('av.id')
            ->limit(self::FILTER_LIMIT * self::VALUE_LIMIT)->get();

        $groups = Attribute::query()->withTranslation()->whereIntegerInRaw('id', $choices->pluck('attribute_id')->unique()->all())
            ->with(['values' => fn ($values) => $values->withTranslation()->whereIntegerInRaw('attribute_values.id', $choices->pluck('value_id')->all())])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Attribute $attribute): array => [
                'attribute' => $attribute->code,
                'name' => self::text($attribute->name) ?: $attribute->code,
                'values' => $attribute->values->map(fn (AttributeValue $value): array => [
                    'code' => $value->code, 'label' => self::text($value->label) ?: $value->code,
                ])->values()->all(),
            ])->all();

        $brands = Brand::query()->whereHas('products', fn (Builder $q) => $q->whereIn('products.id', $productsOnShelf))
            ->orderBy('name')->limit(self::VALUE_LIMIT)->get(['slug', 'name'])
            ->map(fn (Brand $brand): array => ['code' => $brand->slug, 'label' => self::text($brand->name)])->all();

        if ($brands !== []) {
            array_unshift($groups, ['attribute' => 'brand', 'name' => __('catalog.brand'), 'values' => $brands]);
        }
        $storefront = collect($this->sidebarGroups($category))->map(fn (array $group): array => [
            'attribute' => $group['key'],
            'name' => self::text($group['name']),
            'values' => collect($group['items'])->map(fn (array $item): array => [
                'code' => $item['code'], 'label' => self::text($item['label']),
            ])->values()->all(),
        ])->all();

        return collect([...$storefront, ...$groups])->unique('attribute')
            ->map(function (array $group) use ($question): array {
                $values = collect($group['values'])->map(fn (array $value): array => $value + [
                    'relevance' => $question?->relevance($value['label'].' '.$value['code']) ?? 0,
                ])->sortByDesc('relevance')->take(self::VALUE_LIMIT);
                $group['relevance'] = 2 * ($question?->relevance($group['name'].' '.$group['attribute']) ?? 0)
                    + ($values->first()['relevance'] ?? 0);
                $group['values'] = $values->map(fn (array $value): array => [
                    'code' => $value['code'], 'label' => $value['label'],
                ])->values()->all();

                return $group;
            })->sortByDesc('relevance')->take(self::FILTER_LIMIT)
            ->map(fn (array $group): array => ['attribute' => $group['attribute'], 'name' => $group['name'], 'values' => $group['values']])
            ->values()->all();
    }

    public function apply(Builder $products, array $filters, ?Category $category): void
    {
        foreach ($filters as $filter) {
            $attribute = is_array($filter) && is_string($filter['attribute'] ?? null) ? trim($filter['attribute']) : '';
            $value = is_array($filter) && is_string($filter['value'] ?? null) ? trim($filter['value']) : '';
            if ($attribute === '' || $value === '' || mb_strlen($attribute) > 100 || mb_strlen($value) > 120) {
                $products->whereRaw('1 = 0');

                return;
            }

            if (in_array(self::key($attribute), ['brand', 'brendi', 'manufacturer', 'mtsarmoebeli'], true)) {
                $products->whereHas('brand', fn (Builder $brands) => $brands->where(fn (Builder $q) =>
                    $this->exact($q, ['brands.slug', 'brands.name'], $value)));

                continue;
            }

            if ($category !== null && $this->applySidebarChoice($products, $category, $attribute, $value)) {
                continue;
            }

            $products->where(fn (Builder $product) => $product
                ->whereHas('attributeValues', fn (Builder $values) => $values
                    ->whereHas('attribute', fn (Builder $attributes) => $this->matchAttribute($attributes, $attribute))
                    ->where(fn (Builder $answers) => $answers
                        ->where(fn (Builder $codes) => $this->exact($codes, ['attribute_values.code'], $value))
                        ->orWhereHas('translations', fn (Builder $labels) => $this->exact($labels, ['label'], $value))))
                ->orWhereHas('specs', fn (Builder $specs) => $specs
                    ->whereHas('attribute', fn (Builder $attributes) => $this->matchAttribute($attributes, $attribute))
                    ->whereHas('translations', fn (Builder $labels) => $this->exact($labels, ['value'], $value))));
        }
    }

    /** The store maps virtual choices such as room area and screen bands to specific real values. */
    private function applySidebarChoice(Builder $products, Category $category, string $attribute, string $value): bool
    {
        $key = self::key($attribute);
        if (! isset($this->sidebar[$category->id]) && ! preg_match('/^(?:spec|main|logical)-/i', $attribute)) {
            return false;
        }

        foreach ($this->sidebarGroups($category) as $group) {
            if (! in_array($key, [self::key($group['key']), self::key($group['name'])], true)) {
                continue;
            }
            foreach ($group['items'] as $item) {
                if (! in_array(self::key($value), [self::key($item['code']), self::key($item['label'])], true)) {
                    continue;
                }
                if ($group['key'] === 'brand') {
                    $products->whereHas('brand', fn (Builder $brand) => $brand->whereIn('slug', $item['codes'] ?? [$item['code']]));
                } elseif (isset($item['product_ids'])) {
                    $products->whereIntegerInRaw('products.id', $item['product_ids']);
                } elseif (isset($item['sources'])) {
                    $products->where(fn (Builder $q) => $q->whereRaw('1 = 0')
                        ->orWhereHas('attributeValues', function (Builder $values) use ($item): void {
                            $values->where(function (Builder $pairs) use ($item): void {
                                foreach ($item['sources'] as $source => $codes) {
                                    $pairs->orWhere(fn (Builder $pair) => $pair->whereIn('attribute_values.code', $codes)
                                        ->whereHas('attribute', fn (Builder $a) => $a->where('code', $source)));
                                }
                            });
                        }));
                } else {
                    $products->withAttributeValue($group['key'], $item['codes'] ?? [$item['code']]);
                }

                return true;
            }
        }

        return false;
    }

    private function sidebarGroups(Category $category): array
    {
        return $this->sidebar[$category->id] ??= Catalog::listing($category)['groups'];
    }

    private function matchAttribute(Builder $attributes, string $attribute): void
    {
        $attributes->where(fn (Builder $q) => $q
            ->where(fn (Builder $codes) => $this->exact($codes, ['attributes.code'], $attribute))
            ->orWhereHas('translations', fn (Builder $labels) => $this->exact($labels, ['name'], $attribute)));
    }

    private function exact(Builder $query, array $columns, string $value): void
    {
        $compact = self::key($value);
        $query->where(function (Builder $choices) use ($columns, $value, $compact): void {
            foreach ($columns as $column) {
                $choices->orWhere($column, $value);
                if ($compact !== '') {
                    $choices->orWhereRaw('LOWER(REPLACE(REPLACE('.$choices->getQuery()->getGrammar()->wrap($column).", ' ', ''), '-', '')) = ?", [$compact]);
                }
            }
        });
    }

    public static function text(?string $value, int $limit = 100): string
    {
        $text = preg_replace('~<(?:script|style)\b[^>]*>.*?</(?:script|style)>~isu', ' ', (string) $value) ?? '';
        $text = preg_replace('~<(?:br\s*/?|/?(?:p|li|div|tr|h[1-6]))(?:\s[^>]*)?>~iu', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~\s+~u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, $limit);
    }

    private static function key(?string $value): string
    {
        return mb_strtolower(str_replace([' ', '-'], '', trim((string) $value)));
    }
}
