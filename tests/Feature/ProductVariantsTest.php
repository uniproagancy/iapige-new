<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Catalog;
use App\Support\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour and memory switchers on a product page.
 *
 * They were chosen by attribute code — "color" and "storage" — and codes are
 * built from the supplier's own words. A Georgian shop importing Georgian
 * names gets "feri" and "mekhsiereba", so neither ever matched and the page
 * showed nothing however carefully the attributes were marked as variants.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    /** The case from the shop: Georgian names, so Georgian codes. */
    public function test_switchers_appear_for_georgian_attribute_names(): void
    {
        $colour = $this->attribute('ფერი');
        $memory = $this->attribute('მეხსიერება');

        $black = $this->product('შავი 256GB', 'nothing-black');
        $blue = $this->product('ლურჯი 256GB', 'nothing-blue');

        $this->give($black, $colour, 'shavi', 'შავი', '#000000');
        $this->give($blue, $colour, 'lurji', 'ლურჯი', '#0000FF');
        $this->give($black, $memory, '256gb', '256 GB');
        $this->give($blue, $memory, '256gb', '256 GB');

        $page = Catalog::productPage($black->fresh());

        $this->assertCount(2, $page['colors'], 'both colours should be offered');
        $this->assertCount(1, $page['configs']);
    }

    /** A colour is the group whose options carry a swatch. */
    public function test_the_colour_row_is_the_one_with_swatches(): void
    {
        $colour = $this->attribute('ფერი');
        $memory = $this->attribute('მეხსიერება');

        $one = $this->product('შავი', 'p-one');
        $two = $this->product('ლურჯი', 'p-two');

        $this->give($one, $colour, 'shavi', 'შავი', '#000000');
        $this->give($two, $colour, 'lurji', 'ლურჯი', '#0000FF');
        $this->give($one, $memory, '128gb', '128 GB');
        $this->give($two, $memory, '256gb', '256 GB');

        $page = Catalog::productPage($one->fresh());

        $this->assertSame('shavi', $page['colors'][0]['code']);
        $this->assertNotNull($page['colors'][0]['hex']);
        $this->assertNull($page['configs'][0]['hex'], 'memory is not a colour');
    }

    /** English codes still work; nothing about them was wrong. */
    public function test_english_codes_still_work(): void
    {
        $colour = $this->attribute('Colour', 'color');

        $one = $this->product('Black', 'p-black');
        $two = $this->product('Blue', 'p-blue');

        $this->give($one, $colour, 'black', 'Black', '#000000');
        $this->give($two, $colour, 'blue', 'Blue', '#0000FF');

        $this->assertCount(2, Catalog::productPage($one->fresh())['colors']);
    }

    /** A product with no siblings has nothing to switch between. */
    public function test_a_product_on_its_own_offers_nothing(): void
    {
        $alone = $this->product('მარტო', 'alone', group: null);

        $page = Catalog::productPage($alone->fresh());

        $this->assertSame([], $page['colors']);
        $this->assertSame([], $page['configs']);
    }

    /** An attribute nobody marked as a variant is a specification, not a choice. */
    public function test_an_unmarked_attribute_is_not_a_switcher(): void
    {
        $screen = $this->attribute('ეკრანი', variant: false);

        $one = $this->product('ერთი', 'p1');
        $two = $this->product('ორი', 'p2');

        $this->give($one, $screen, '6-inch', '6"');
        $this->give($two, $screen, '7-inch', '7"');

        $this->assertSame([], Catalog::productPage($one->fresh())['colors']);
        $this->assertSame([], Catalog::productPage($one->fresh())['configs']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'ტელეფონები', 'slug' => 'phones']]);
    }

    protected function attribute(string $name, ?string $code = null, bool $variant = true): Attribute
    {
        $attribute = Attribute::create([
            'code' => $code ?? Slug::make($name),
            'is_filterable' => true,
            'is_variant' => $variant,
            'sort_order' => 1,
        ]);

        $attribute->saveTranslations(['ka' => ['name' => $name]]);

        return $attribute->fresh();
    }

    protected function give(Product $product, Attribute $attribute, string $code, string $label, ?string $hex = null): void
    {
        $value = AttributeValue::firstOrCreate(
            ['attribute_id' => $attribute->id, 'code' => $code],
            ['sort_order' => 0, 'color_hex' => $hex],
        );

        $value->saveTranslations(['ka' => ['label' => $label]]);

        $product->attributeValues()->syncWithoutDetaching([$value->id]);
    }

    protected function product(string $name, string $slug, ?string $group = 'nothing-3a'): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.$slug,
            'category_id' => $this->category->id,
            'price' => 899,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
            'variant_group' => $group,
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => $slug]]);

        return $product->fresh();
    }
}
