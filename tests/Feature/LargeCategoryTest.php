<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The catalogue's largest category answered 500 and no other did.
 *
 * listing() fetched every listable product in the category and all of its
 * descendants — each one with its brand, its images, its category, its
 * attribute values and its running campaigns — in order to count them and to
 * collect the brands into a sidebar. A leaf category survived that. A root
 * holding tens of thousands did not: it ran out of memory. The products it
 * built from all that work were then read by nothing at all, because the
 * listing page queries its own, a page at a time.
 *
 * That is why only one page on the shop was broken, and why it looked like a
 * problem with that one category rather than with the catalogue's size. So
 * what is pinned here is not the category: it is that the work no longer
 * grows with the number of products in it.
 */
class LargeCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected Category $root;

    protected Category $child;

    /* ------------------------------------------------------------------ the size of it */

    /**
     * Ten times the products, the same number of queries.
     *
     * The guard that matters, and the only one a test can hold: a listing
     * that asks the database a fixed number of questions cannot be brought
     * down by how much is in it.
     */
    public function test_the_work_does_not_grow_with_the_catalogue(): void
    {
        $this->products(3);

        // the languages and the interface strings are read once per process
        Catalog::listing($this->root);

        $small = $this->queriesFor(fn () => Catalog::listing($this->root));

        $this->products(30);
        $large = $this->queriesFor(fn () => Catalog::listing($this->root));

        $this->assertSame($small, $large, 'listing() asked more questions of a larger category');
    }

    /**
     * No query fetches product rows.
     *
     * Everything about the products is now a count, a min, a max, or an
     * exists — which is the whole point: the listing asks how many and how
     * much, and those are questions a database answers without handing
     * anything over.
     *
     * The "select * from products" inside an exists() does not count: it is
     * correlated to one brand at a time and never leaves the server.
     */
    public function test_the_products_are_never_all_fetched(): void
    {
        $this->products(5);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Catalog::listing($this->root);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/^select \*\s+from [`"]?products[`"]?/i',
                $query['query'],
                'listing() still fetches whole product rows: '.$query['query'],
            );
        }
    }

    /**
     * The guard above has to be able to fail.
     *
     * An assertion over a query log is only worth keeping if it recognises
     * the query it was written for, and this is the one listing() used to
     * make.
     */
    public function test_that_guard_would_catch_the_old_query(): void
    {
        $this->assertMatchesRegularExpression(
            '/^select \*\s+from [`"]?products[`"]?/i',
            'select * from "products" where "status" = ? and "category_id" in (?, ?)',
        );
    }

    /* ------------------------------------------------------------------ and still correct */

    public function test_it_counts_everything_under_the_category(): void
    {
        $this->products(4);

        $this->assertSame(4, Catalog::listing($this->root)['total']);
    }

    /** A product out of stock is not in the listing and is not counted. */
    public function test_what_cannot_be_bought_is_not_counted(): void
    {
        $this->products(3);
        Product::query()->limit(1)->update(['stock' => 0]);

        $this->assertSame(2, Catalog::listing($this->root)['total']);
    }

    public function test_the_price_range_is_the_real_one(): void
    {
        $this->products(3, prices: [150, 2480, 900]);

        $listing = Catalog::listing($this->root);

        $this->assertSame(100, $listing['priceMin']);   // rounded down to the hundred
        $this->assertSame(2500, $listing['priceMax']);  // and up
    }

    /** With nothing in it, the slider still has somewhere to sit. */
    public function test_an_empty_category_still_has_a_price_range(): void
    {
        $listing = Catalog::listing($this->root);

        $this->assertSame(0, $listing['total']);
        $this->assertSame(0, $listing['priceMin']);
        $this->assertSame(1000, $listing['priceMax']);
    }

    public function test_the_brands_of_the_category_are_offered(): void
    {
        $this->products(2);

        $brands = collect(Catalog::listing($this->root)['groups'])
            ->firstWhere('key', 'brand');

        $this->assertNotNull($brands);
        $this->assertSame(['bosch', 'samsung'], collect($brands['items'])->pluck('code')->sort()->values()->all());
    }

    /** A brand nothing in this category carries is not a filter for it. */
    public function test_a_brand_from_elsewhere_is_not_offered(): void
    {
        $this->products(2);

        $other = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 9]);
        $other->saveTranslations(['ka' => ['name' => 'სხვა', 'slug' => 'skhva']]);

        $elsewhere = Brand::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'is_active' => true]);
        $this->product($other, $elsewhere, 500, 'x');

        $brands = collect(Catalog::listing($this->root)['groups'])->firstWhere('key', 'brand');

        $this->assertNotContains('elsewhere', collect($brands['items'])->pluck('code')->all());
    }

    /** The options are the ones the category's own products carry. */
    public function test_the_filter_options_come_from_this_category(): void
    {
        $this->products(2);

        $colour = Attribute::create([
            'code' => 'peri', 'type' => 'select',
            'is_filterable' => true, 'is_variant' => false, 'sort_order' => 1,
        ]);
        $colour->saveTranslations(['ka' => ['name' => 'ფერი']]);

        foreach (['shavi' => 'შავი', 'tetri' => 'თეთრი'] as $code => $label) {
            $value = AttributeValue::create([
                'attribute_id' => $colour->id, 'code' => $code, 'sort_order' => 1,
            ]);
            $value->saveTranslations(['ka' => ['label' => $label]]);

            Product::query()->get()->each(fn (Product $p) => $p->attributeValues()->syncWithoutDetaching([$value->id]));
        }

        $group = collect(Catalog::listing($this->root)['groups'])->firstWhere('key', 'peri');

        $this->assertNotNull($group);
        $this->assertCount(2, $group['items']);
    }

    /** A child's products belong to the parent's listing too. */
    public function test_the_children_are_counted_with_the_parent(): void
    {
        $this->product($this->root, $this->brand('bosch'), 100, 'a');
        $this->product($this->child, $this->brand('samsung'), 200, 'b');

        $this->assertSame(2, Catalog::listing($this->root)['total']);
        $this->assertSame(1, Catalog::listing($this->child)['total']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->root = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->root->saveTranslations(['ka' => ['name' => 'მობილური ტელეფონები', 'slug' => 'mobiluri']]);

        $this->child = Category::create(['parent_id' => $this->root->id, 'is_active' => true, 'sort_order' => 1]);
        $this->child->saveTranslations(['ka' => ['name' => 'აქსესუარები', 'slug' => 'aksesuarebi']]);
    }

    protected function queriesFor(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param  array<int, int>  $prices */
    protected function products(int $many, array $prices = []): void
    {
        for ($i = 0; $i < $many; $i++) {
            $this->product(
                $i % 2 === 0 ? $this->root : $this->child,
                $this->brand($i % 2 === 0 ? 'bosch' : 'samsung'),
                $prices[$i] ?? (100 + $i),
                'p'.$i.'-'.str()->random(4),
            );
        }
    }

    protected function brand(string $slug): Brand
    {
        return Brand::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'is_active' => true],
        );
    }

    protected function product(Category $category, Brand $brand, int $price, string $key): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.$key,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'price' => $price,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი '.$key, 'slug' => $key]]);

        return $product->fresh();
    }
}
