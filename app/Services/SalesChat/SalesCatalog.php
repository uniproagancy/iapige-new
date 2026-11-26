<?php

namespace App\Services\SalesChat;

use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Support\Catalog;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Builder;

class SalesCatalog
{
    private const PRODUCT_LIMIT = 5;

    private const CATEGORY_LIMIT = 2;

    private const CANDIDATE_LIMIT = 48;

    private const SPEC_LIMIT = 24;

    private const DESCRIPTION_LIMIT = 1800;

    private const VARIANT_LIMIT = 4;

    private ?SalesCatalogFilters $filterService = null;

    public function search(string $query, ?int $budget = null): array
    {
        return $this->find($query, $budget);
    }

    /** @return list<array{id:int,type:'product'|'category',name:string,url:string,price_text?:string}> */
    public function find(string $query, ?int $maxPrice, array $filters = [], ?int $minPrice = null, ?string $sort = null): array
    {
        $intent = new SalesCatalogQuery($query);
        if ($intent->words === [] && $filters === []) {
            return [];
        }

        $matches = $this->categoryMatches($intent);
        $categories = collect($matches)->map(fn (array $match) => $this->categoryCard($match['category']))
            ->filter()->take(self::CATEGORY_LIMIT)->values()->all();
        if (count($filters) > 12 || ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice)) {
            return $categories;
        }

        $products = $this->products();
        $this->filters()->apply($products, $filters, $matches[0]['category'] ?? null);
        $terms = array_values($intent->words);
        if ($matches !== []) {
            $best = $matches[0];
            $branch = collect($matches)->takeWhile(fn (array $match) => $match['score'] === $best['score'])
                ->filter(fn (array $match) => $match['matched'] === $best['matched'])
                ->flatMap(fn (array $match) => $match['category']->descendantAndSelfIds())->unique()->all();
            $products->whereIntegerInRaw('category_id', array_intersect($branch, array_keys(Catalog::stocked())));
            $terms = $intent->without($best['matched']);
        }

        if ($matches === [] && count($terms) > 1) {
            $phrase = clone $products;
            $this->matchProductWord($phrase, $intent->phrase);
            $cards = $this->productCards($phrase, $intent->phrase, $minPrice, $maxPrice, $sort);
            if ($cards !== []) {
                return [...$cards, ...$categories];
            }
        }

        $nameAndFields = clone $products;
        foreach ($terms as $word) {
            $this->matchProductWord($nameAndFields, $word);
        }
        $cards = $this->productCards($nameAndFields, $intent->phrase, $minPrice, $maxPrice, $sort);

        if ($cards === [] && $terms !== []) {
            foreach ($terms as $word) {
                $this->matchProductWord($products, $word, true);
            }
            $cards = $this->productCards($products, $intent->phrase, $minPrice, $maxPrice, $sort);
        }

        return [...$cards, ...$categories];
    }

    private function matchProductWord(Builder $products, string $word, bool $includeDescription = false): void
    {
        $products->where(function (Builder $candidates) use ($word, $includeDescription): void {
            Catalog::matching($candidates, $word);
            if (preg_match('/^(\d+)(gb|tb|mb|hz|inch)$/iuD', $word, $measurement)) {
                $candidates->orWhere(fn (Builder $spaced) => Catalog::matching($spaced, $measurement[1].' '.$measurement[2]));
            }
            // A genitive such as „სამსუნგის“ can name the brand stored as Samsung.
            if (preg_match('/^(\p{Georgian}{4,})ის$/uD', $word, $parts)) {
                $brand = Slug::make($parts[1]);
                $candidates->orWhereHas('brand', fn (Builder $brands) => $brands->where('slug', $brand)
                    ->orWhereRaw('LOWER(name) = ?', [$brand]));
            }

            if (mb_strlen($word) < 3 || ctype_digit($word)) {
                return;
            }
            $compact = mb_strtolower(str_replace([' ', '-'], '', $word));
            $candidates->orWhereHas('attributeValues', fn (Builder $answers) => $answers
                ->whereRaw("LOWER(REPLACE(REPLACE(attribute_values.code, ' ', ''), '-', '')) = ?", [$compact])
                ->orWhereHas('translations', fn (Builder $labels) => $labels
                    ->whereRaw("LOWER(REPLACE(REPLACE(label, ' ', ''), '-', '')) = ?", [$compact])))
                ->orWhereHas('specs.translations', fn (Builder $specs) => $specs
                    ->whereRaw("LOWER(REPLACE(REPLACE(value, ' ', ''), '-', '')) = ?", [$compact]));
            if ($includeDescription && ! preg_match('/^\d/u', $word)) {
                $candidates->orWhereHas('translations', fn (Builder $details) => $details->where('description', 'like', "%{$word}%"))
                    ->orWhereHas('attributeValues.translations', fn (Builder $labels) => $labels->where('label', 'like', "%{$word}%"))
                    ->orWhereHas('specs.translations', fn (Builder $specs) => $specs->where('value', 'like', "%{$word}%"));
            }
        });
    }

    private function productCards(Builder $products, string $phrase, ?int $minPrice, ?int $maxPrice, ?string $sort): array
    {
        if ($maxPrice !== null) {
            $products->where(fn (Builder $products) => $products
                ->where('price', '<=', $maxPrice)->orWhereHas('livePromotions'));
        }
        if ($minPrice !== null) {
            $products->where('price', '>=', $minPrice);
        }

        $direction = match ($sort) {
            'price_asc', 'price-asc' => 'asc',
            'price_desc', 'price-desc' => 'desc',
            default => null,
        };
        if ($direction !== null) {
            $products->orderBy('price', $direction);
        } elseif ($phrase !== '') {
            $model = mb_strtolower($phrase);
            $products->orderByRaw('CASE WHEN LOWER(products.sku) = ? OR EXISTS (SELECT 1 FROM product_translations WHERE product_id = products.id AND (LOWER(name) = ? OR slug = ?)) THEN 0 ELSE 1 END', [$model, $model, Slug::make($phrase)]);
        }

        if ($direction === null && $maxPrice !== null) {
            $products->orderBy('price');
        }
        $candidates = (clone $products)->orderByDesc('sales_count')->orderBy('id')->limit(self::CANDIDATE_LIMIT)->get();
        if ($direction !== null || $maxPrice !== null) {
            // The previous regular price can put a promoted product outside the first candidate page.
            $promoted = (clone $products)->whereHas('livePromotions')->orderBy('id')->limit(self::CANDIDATE_LIMIT)->get();
            $candidates = $candidates->merge($promoted);
        }
        if ($direction !== null) {
            $candidates = $candidates->sortBy(fn (Product $product) => $product->sellingPrice(), SORT_NUMERIC, $direction === 'desc');
        }

        return $candidates->map(fn (Product $product) => $this->productCard($product, $maxPrice, $minPrice))
            ->filter()->take(self::PRODUCT_LIMIT)->values()->all();
    }

    private function categoryMatches(SalesCatalogQuery $intent): array
    {
        if ($intent->words === []) {
            return [];
        }

        $locales = array_unique([app()->getLocale(), Language::defaultCode(), 'ka', 'en']);

        return Category::active()->whereIntegerInRaw('id', array_keys(Catalog::stocked()))
            ->with(['translations' => fn ($translations) => $translations->whereIn('locale', $locales)])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Category $category) use ($intent) {
                $match = $intent->match($category);

                return $match === null ? null : $match + ['category' => $category];
            })->filter()->sortByDesc('score')->values()->all();
    }

    public function categoryContext(string $query): array
    {
        $intent = new SalesCatalogQuery($query);

        return collect($this->categoryMatches($intent))->pluck('category')->take(self::CATEGORY_LIMIT)
            ->map(fn (Category $category, int $index) => $this->categoryFacts($category, $index === 0, $intent))->filter()->values()->all();
    }

    public function categoryContextFromSlug(string $slug, string $question): array
    {
        $category = $this->categoryModelFromSlug($slug);
        $facts = $category ? $this->categoryFacts($category, true, new SalesCatalogQuery($question)) : null;

        return $facts === null ? [] : [$facts];
    }

    public function categoryFromSlug(string $slug): ?array
    {
        $category = $this->categoryModelFromSlug($slug);

        return $category ? $this->categoryCard($category) : null;
    }

    private function categoryModelFromSlug(string $slug): ?Category
    {
        if (! $this->validSlug($slug)) {
            return null;
        }

        return Category::active()->withTranslation()->whereTranslation('slug', $slug, false)
            ->whereIntegerInRaw('id', array_keys(Catalog::stocked()))->first();
    }

    public function product(int $id): ?array
    {
        $product = $this->products()->find($id);

        return $product ? $this->productCard($product) : null;
    }

    public function productFromSlug(string $slug): ?array
    {
        if (! $this->validSlug($slug)) {
            return null;
        }

        $product = $this->products()->whereTranslation('slug', $slug, false)->first();

        return $product ? $this->productCard($product) : null;
    }

    public function detailsFromSlug(string $slug, ?string $question = null): ?array
    {
        if (! $this->validSlug($slug)) {
            return null;
        }

        $product = $this->detailRelations(Product::active()->withTranslation()
            ->whereTranslation('slug', $slug, false))->first();

        return $product ? $this->productFacts($product, $question === null ? null : new SalesCatalogQuery($question)) : null;
    }

    public function referenceDetails(string $query, string $question): array
    {
        $model = mb_strtolower(trim($query));
        if (mb_strlen($model) < 3 || mb_strlen($model) > 120) {
            return [];
        }

        $public = Product::active()->withTranslation();
        $exact = (clone $public)->where(fn (Builder $product) => $product
            ->whereRaw('LOWER(products.sku) = ?', [$model])
            ->orWhereHas('translations', fn (Builder $titles) => $titles->whereRaw('LOWER(name) = ?', [$model])
                ->orWhere('slug', $model)))->limit(2)->get();

        if ($exact->isEmpty() && preg_match('/^(?=.*[a-z])(?=.*\d)[a-z\d-]{5,35}$/D', $model)) {
            $boundary = '/(?<![\p{L}\p{N}])'.preg_quote($model, '/').'(?![\p{L}\p{N}])/iu';
            $candidates = $public->whereHas('translations', fn (Builder $titles) => $titles
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$model.'%']))->limit(25)->get();
            if ($candidates->count() < 25) {
                $exact = $candidates->filter(fn (Product $product) => preg_match($boundary, $product->name ?? '') === 1);
            }
        }
        if ($exact->count() !== 1) {
            return [];
        }

        $product = $this->detailRelations(Product::active()->withTranslation())->find($exact->first()->id);
        $fact = $product ? $this->productFacts($product, new SalesCatalogQuery($question)) : null;

        return $fact === null ? [] : [$fact];
    }

    public function facts(array $cards, ?string $question = null): array
    {
        $positiveIds = fn (string $type, int $limit): array => collect($cards)->where('type', $type)->pluck('id')
            ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->take($limit)->all();
        $ids = $positiveIds('product', self::PRODUCT_LIMIT);
        $categoryIds = array_intersect($positiveIds('category', self::CATEGORY_LIMIT), array_keys(Catalog::stocked()));
        $intent = $question === null ? null : new SalesCatalogQuery($question);

        $products = $ids === [] ? [] : $this->detailRelations($this->products()->whereIntegerInRaw('products.id', $ids))
            ->get()->map(fn (Product $product) => $this->productFacts($product, $intent))->filter()->values()->all();
        $categories = $categoryIds === [] ? [] : Category::active()->withTranslation()->whereIntegerInRaw('id', $categoryIds)
            ->get()->map(fn (Category $category) => $this->categoryFacts($category, false, $intent))->filter()->values()->all();

        return [...$products, ...$categories];
    }

    private function detailRelations(Builder $query): Builder
    {
        return $query->with([
            'brand', 'livePromotions',
            'category' => fn ($category) => $category->withTranslation(),
            'specs' => fn ($specs) => $specs->withTranslation()
                ->with(['attribute' => fn ($attribute) => $attribute->withTranslation()]),
            'attributeValues' => fn ($values) => $values->withTranslation()
                ->with(['attribute' => fn ($attribute) => $attribute->withTranslation()]),
        ]);
    }

    private function productFacts(Product $product, ?SalesCatalogQuery $question): ?array
    {
        $card = $this->productIdentity($product);
        if ($card === null) {
            return null;
        }
        $price = $product->sellingPrice();
        if ($price > 0) {
            $card['price_text'] = money($price, floor($price) === $price ? 0 : 2);
        }

        $details = $card + [
            'brand' => SalesCatalogFilters::text($product->brand?->name),
            'category' => $product->category?->is_active ? $this->categoryCard($product->category) : null,
            'in_stock' => $product->inStock() && ! $product->is_preorder,
            'availability' => $product->is_preorder ? 'preorder' : ($product->inStock() ? 'in_stock' : 'out_of_stock'),
            'summary' => SalesCatalogFilters::text($product->summary, 350),
            'description' => $this->descriptionExcerpt($product->description, self::DESCRIPTION_LIMIT, $question),
            'specs' => $product->specs->sortByDesc('is_key')->map(fn (ProductSpec $spec) => $this->specFact($spec))
                ->filter()->unique(fn ($spec) => $spec['name'].'='.$spec['value'])
                ->sortByDesc(fn (array $spec) => 2 * ($question?->relevance($spec['name']) ?? 0) + ($question?->relevance($spec['value']) ?? 0))
                ->take(self::SPEC_LIMIT)->values()->all(),
            'filters' => $product->attributeValues->filter(fn (AttributeValue $value) => $value->attribute?->is_filterable || $value->attribute?->is_variant)
                ->map(fn (AttributeValue $value) => $this->optionFact($value))->filter()
                ->sortByDesc(fn (array $option) => 2 * ($question?->relevance($option['name']) ?? 0) + ($question?->relevance($option['value']) ?? 0))
                ->take(self::SPEC_LIMIT)->values()->all(),
        ];

        if (filled($product->variant_group)) {
            $details['variants'] = $this->products()
                ->where('variant_group', $product->variant_group)->where('category_id', $product->category_id)
                ->where('products.id', '<>', $product->id)->with(['attributeValues.attribute' => fn ($attribute) => $attribute->withTranslation()])
                ->orderByDesc('sales_count')->orderBy('id')->limit(self::VARIANT_LIMIT)->get()
                ->map(function (Product $sibling): ?array {
                    $card = $this->productCard($sibling);

                    return $card === null ? null : $card + ['options' => $sibling->attributeValues
                        ->filter(fn (AttributeValue $value) => $value->attribute?->is_variant)
                        ->map(fn (AttributeValue $value) => $this->optionFact($value))->filter()->take(8)->values()->all()];
                })->filter()->values()->all();
        }

        return $details;
    }

    private function descriptionExcerpt(?string $description, int $limit, ?SalesCatalogQuery $question): string
    {
        if ($question === null) {
            return SalesCatalogFilters::text($description, $limit);
        }

        $text = SalesCatalogFilters::text($description, PHP_INT_MAX);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Inspect the text beyond its prefix, including long descriptions with no sentence breaks.
        $segments = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $relevant = collect($segments)->flatMap(fn (string $segment) => mb_str_split($segment, 450))
            ->map(fn (string $segment) => ['text' => $segment, 'score' => $question->relevance($segment)])
            ->filter(fn (array $segment) => $segment['score'] > 0)->sortByDesc('score')->take(4)->pluck('text');

        return mb_substr($relevant->isEmpty() ? $text : $relevant->implode("\n…\n")."\n…\n".$text, 0, $limit);
    }

    private function optionFact(AttributeValue $value): ?array
    {
        $attribute = $value->attribute;
        $name = SalesCatalogFilters::text($attribute?->name);
        $label = SalesCatalogFilters::text($value->label);
        if ($name === '' || $label === '') {
            return null;
        }

        return ['attribute' => $attribute->code, 'name' => $name, 'code' => $value->code, 'value' => $label];
    }

    private function categoryFacts(Category $category, bool $includeFilters, ?SalesCatalogQuery $question = null): ?array
    {
        $card = $this->categoryCard($category);
        if ($card === null) {
            return null;
        }

        $publicIds = array_keys(Catalog::stocked());
        $children = $category->children()->active()->withTranslation()->whereIntegerInRaw('id', $publicIds)->get();
        $details = $card + [
            'description' => $this->descriptionExcerpt($category->description, 450, $question),
            'breadcrumbs' => collect($category->ancestorsAndSelf())->filter(fn (Category $item) => $item->is_active)
                ->map(fn (Category $item) => $this->categoryCard($item))->filter()->values()->all(),
            'subcategories' => $children->map(fn (Category $child) => $this->categoryCard($child))->filter()
                ->sortByDesc(fn (array $child) => $question?->relevance($child['name']) ?? 0)->take(12)->values()->all(),
        ];
        if ($includeFilters) {
            $branch = array_intersect($category->descendantAndSelfIds(), $publicIds);
            $details['filters'] = $this->filters()->choices($this->products()->whereIntegerInRaw('category_id', $branch), $category, $question);
        }

        return $details;
    }

    private function filters(): SalesCatalogFilters
    {
        return $this->filterService ??= new SalesCatalogFilters;
    }

    private function products(): Builder
    {
        return Catalog::productQuery()
            ->where('is_preorder', false)
            ->where('stock', '>', 0)
            ->where('price', '>', 0);
    }

    private function productCard(Product $product, ?int $budget = null, ?int $minPrice = null): ?array
    {
        $identity = $this->productIdentity($product);
        $price = $product->sellingPrice();
        if ($identity === null || $price <= 0 || ($budget !== null && $price > $budget) || ($minPrice !== null && $price < $minPrice)) {
            return null;
        }

        return $identity + ['price_text' => money($price, floor($price) === $price ? 0 : 2)];
    }

    private function productIdentity(Product $product): ?array
    {
        $name = SalesCatalogFilters::text($product->name, 200);
        if ($name === '' || ! $this->validSlug($product->slug)) {
            return null;
        }

        return [
            'id' => (int) $product->id,
            'type' => 'product',
            'name' => $name,
            'url' => route('product', $product->slug),
        ];
    }

    private function categoryCard(Category $category): ?array
    {
        $name = SalesCatalogFilters::text($category->name, 200);
        if ($name === '' || ! $this->validSlug($category->slug)) {
            return null;
        }

        return ['id' => (int) $category->id, 'type' => 'category', 'name' => $name, 'url' => route('catalog', $category->slug)];
    }

    private function specFact(ProductSpec $spec): ?array
    {
        $name = SalesCatalogFilters::text($spec->attribute?->name, 120);
        $value = SalesCatalogFilters::text($spec->value, 190);
        if ($name === '' || in_array(mb_strtolower($value), ['', '-', '–', '—', 'n/a'], true)) {
            return null;
        }

        return ['name' => $name, 'value' => $value];
    }

    private function validSlug(?string $slug): bool
    {
        return $slug !== null && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._~-]*$/uD', $slug) === 1;
    }
}
