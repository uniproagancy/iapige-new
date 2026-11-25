<?php

namespace App\Services\SalesChat;

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

    private const CANDIDATE_LIMIT = 40;

    /** @return list<array{id:int,type:'product'|'category',name:string,url:string,price_text?:string}> */
    public function search(string $query, ?int $budget = null): array
    {
        $intent = new SalesCatalogQuery($query);

        if ($intent->words === []) {
            return [];
        }

        $matches = $this->categoryMatches($intent);
        $products = $this->products();

        if ($matches === []) {
            $this->matchProductWord($products, $intent->phrase);
        } else {
            $best = $matches[0];
            $branch = collect($matches)->takeWhile(fn (array $match) => $match['score'] === $best['score'])
                ->filter(fn (array $match) => $match['matched'] === $best['matched'])
                ->flatMap(fn (array $match) => $match['category']->descendantAndSelfIds())->unique()->all();
            $publicIds = array_keys(Catalog::stocked());
            $products->whereIntegerInRaw('category_id', array_values(array_intersect($branch, $publicIds)));
            foreach ($intent->without($best['matched']) as $word) {
                $this->matchProductWord($products, $word);
            }
        }

        $cards = $this->productCards($products, $budget);

        if ($cards === [] && $matches === [] && array_values($intent->words) !== [$intent->phrase]) {
            $products = $this->products();
            foreach ($intent->words as $word) {
                $this->matchProductWord($products, $word);
            }
            $cards = $this->productCards($products, $budget);
        }

        $categories = collect($matches)->map(fn (array $match) => $this->categoryCard($match['category']))
            ->filter()->take(self::CATEGORY_LIMIT)->values()->all();

        return [...$cards, ...$categories];
    }

    private function matchProductWord(Builder $products, string $word): void
    {
        if (! preg_match('/^(\p{Georgian}{4,})ის$/uD', $word, $parts)) {
            Catalog::matching($products, $word);

            return;
        }

        // A genitive such as „სამსუნგის“ can name the brand stored as Samsung.
        $brand = Slug::make($parts[1]);
        $products->where(fn (Builder $products) => Catalog::matching($products, $word)
            ->orWhereHas('brand', fn (Builder $brands) => $brands->where('slug', $brand)
                ->orWhereRaw('LOWER(name) = ?', [$brand])));
    }

    private function productCards(Builder $products, ?int $budget): array
    {
        if ($budget !== null) {
            $products->where(fn (Builder $products) => $products
                ->where('price', '<=', $budget)
                ->orWhereHas('livePromotions'))
                ->orderBy('price');
        }

        return $products->orderByDesc('sales_count')->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)->get()
            ->map(fn (Product $product) => $this->productCard($product, $budget))
            ->filter()->take(self::PRODUCT_LIMIT)->values()->all();
    }

    private function categoryMatches(SalesCatalogQuery $intent): array
    {
        $locales = array_unique([app()->getLocale(), Language::defaultCode(), 'ka', 'en']);

        return Category::active()->whereIntegerInRaw('id', array_keys(Catalog::stocked()))
            ->with(['translations' => fn ($translations) => $translations->whereIn('locale', $locales)])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Category $category) use ($intent) {
                $match = $intent->match($category);

                return $match === null ? null : $match + ['category' => $category];
            })->filter()->sortByDesc('score')->values()->all();
    }

    /** @return array{id:int,type:'product',name:string,url:string,price_text:string}|null */
    public function product(int $id): ?array
    {
        $product = $this->products()->find($id);

        return $product ? $this->productCard($product) : null;
    }

    /** @return array{id:int,type:'product',name:string,url:string,price_text:string}|null */
    public function productFromSlug(string $slug): ?array
    {
        if (! $this->validSlug($slug)) {
            return null;
        }

        $product = $this->products()->whereTranslation('slug', $slug, false)->first();

        return $product ? $this->productCard($product) : null;
    }

    /** @return list<array{id:int,specs:list<array{name:string,value:string}>}> */
    public function facts(array $cards): array
    {
        $ids = collect($cards)->where('type', 'product')->pluck('id')
            ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->take(self::PRODUCT_LIMIT)->all();

        if ($ids === []) {
            return [];
        }

        return $this->products()->withoutEagerLoads()->select('id')->whereIntegerInRaw('id', $ids)
            ->with(['specs' => fn ($specs) => $specs->withTranslation()
                ->with(['attribute' => fn ($attribute) => $attribute->withTranslation()])])
            ->get()->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'specs' => $product->specs->sortByDesc('is_key')
                    ->map(fn (ProductSpec $spec) => $this->specFact($spec))
                    ->filter()->take(4)->values()->all(),
            ])->filter(fn (array $product) => $product['specs'] !== [])->values()->all();
    }

    private function products(): Builder
    {
        return Catalog::productQuery()
            ->where('is_preorder', false)
            ->where('stock', '>', 0)
            ->where('price', '>', 0);
    }

    private function productCard(Product $product, ?int $budget = null): ?array
    {
        $name = $this->displayName($product->name);

        if ($name === '' || ! $this->validSlug($product->slug)) {
            return null;
        }

        $card = Catalog::card($product);
        $price = (float) $card['price'];

        if ($price <= 0 || ($budget !== null && $price > $budget)) {
            return null;
        }

        return [
            'id' => (int) $card['id'],
            'type' => 'product',
            'name' => $name,
            'url' => $card['url'],
            'price_text' => money($price, floor($price) === $price ? 0 : 2),
        ];
    }

    private function categoryCard(Category $category): ?array
    {
        $name = $this->displayName($category->name);

        if ($name === '' || ! $this->validSlug($category->slug)) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'type' => 'category',
            'name' => $name,
            'url' => route('catalog', $category->slug),
        ];
    }

    private function specFact(ProductSpec $spec): ?array
    {
        $name = $this->displayName($spec->attribute?->name);
        $value = trim(strip_tags((string) $spec->value));

        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($value) > 100
            || in_array(mb_strtolower($value), ['', '-', '–', '—', 'n/a'], true)) {
            return null;
        }

        return ['name' => $name, 'value' => $value];
    }

    private function displayName(?string $name): string
    {
        return mb_substr(trim(strip_tags((string) $name)), 0, 200);
    }

    private function validSlug(?string $slug): bool
    {
        return $slug !== null && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._~-]*$/uD', $slug) === 1;
    }
}
