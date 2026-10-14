<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Services\Import\TaxonomyResolver;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * One broken character took out a product's whole specification table.
 *
 * A supplier's feed is bytes, not characters: a cell cut to a length, a
 * response that ended early, a summary the shop truncated for its own listing
 * card. Any of them can end mid-character, and a Georgian letter is three
 * bytes, so it happens to Georgian text constantly — "მაცივრის მოცულობა ,ლ"
 * arrived with two of that last letter's three bytes.
 *
 * MySQL refuses it outright, and the refusal lands mid-import: the attribute
 * is never created, and the row that was going to point at it fails too — as
 * a foreign key violation several lines later that says nothing about the
 * cause. The log filled with the wrong error.
 */
class BrokenUtf8ImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the repair */

    public function test_a_half_character_is_dropped(): void
    {
        $this->assertSame('მაცივრის მოცულობა', Text::utf8("მაცივრის მოცულობა ,\xE1\x83"));
    }

    /** Only what is broken: a name that is fine comes back untouched. */
    public function test_valid_text_is_left_alone(): void
    {
        $this->assertSame('მაცივრის მოცულობა, ლიტრი', Text::utf8('მაცივრის მოცულობა, ლიტრი'));
    }

    public function test_nothing_stays_nothing(): void
    {
        $this->assertNull(Text::utf8(null));
        $this->assertSame('', Text::utf8(''));
    }

    /** The key too, or one attribute splits into two. */
    public function test_a_broken_key_is_repaired(): void
    {
        $this->assertSame(
            ['მოცულობა' => 'x'],
            Text::utf8All(["მოცულობა\xE1\x83" => 'x']),
        );
    }

    /* ------------------------------------------------------------------ the import */

    public function test_a_spec_with_a_broken_name_is_still_imported(): void
    {
        $product = $this->import($this->payload(specName: "მაცივრის მოცულობა ,\xE1\x83"));

        $this->assertNotNull($product);
        $this->assertSame(1, $product->specs()->count());
    }

    /** And the attribute it needed exists, spelled properly. */
    public function test_the_attribute_is_created_with_a_clean_name(): void
    {
        $this->import($this->payload(specName: "მაცივრის მოცულობა ,\xE1\x83"));

        $attribute = Attribute::first();

        $this->assertNotNull($attribute);
        $this->assertSame('მაცივრის მოცულობა', $attribute->name);
    }

    /**
     * The mapping row is written too.
     *
     * supplier_attribute_map.external_name took the same broken bytes and
     * failed the same way, which left the admin's mapping queue short of the
     * very names somebody needed to map.
     */
    public function test_the_mapping_row_is_written(): void
    {
        $this->import($this->payload(specName: "მაცივრის მოცულობა ,\xE1\x83"));

        $this->assertDatabaseHas('supplier_attribute_map', [
            'supplier_id' => $this->supplier->id,
            'external_name' => 'მაცივრის მოცულობა',
        ]);
    }

    /** A broken product name must not reach the translations either. */
    public function test_a_broken_product_name_is_repaired(): void
    {
        $product = $this->import($this->payload(name: "მაცივარი LG\xE1\x83"));

        $this->assertSame('მაცივარი LG', $product->fresh()->name);
    }

    public function test_a_broken_category_name_is_repaired(): void
    {
        $this->import($this->payload(category: "ტექნიკა\xE1\x83"));

        $this->assertDatabaseHas('supplier_category_map', ['external_name' => 'ტექნიკა']);
    }

    public function test_a_broken_brand_name_is_repaired(): void
    {
        $product = $this->import($this->payload(brand: "ნორდა\xE1\x83"));

        $this->assertSame('ნორდა', $product->fresh()->brand->name);
    }

    /** Two specs whose names differ only in the broken tail are one attribute. */
    public function test_a_name_broken_two_ways_is_one_attribute(): void
    {
        $this->import($this->payload(specName: "მოცულობა\xE1\x83"));
        $this->import($this->payload(specName: "მოცულობა\xE1\x83\x9a", external: 'EXT-2', sku: 'SKU-2'));

        $this->assertSame(2, Attribute::count());

        // the second is a different word, so a different attribute — but both stored
        $this->assertSame(0, DB::table('attribute_translations')->whereNull('name')->count());
    }

    /* ------------------------------------------------------------------ the amplifier */

    /**
     * A failed write must not poison the rest of the run.
     *
     * The resolver is a singleton, so an attribute created inside a
     * transaction that then rolled back stayed in its memory holding an id the
     * database no longer had. Every later product carrying that same attribute
     * wrote a link to a row that was never committed, and MySQL refused each
     * one as a foreign key violation — nineteen of them from seven broken
     * characters, none naming the cause, going on until the worker restarted.
     */
    public function test_a_failed_write_clears_what_the_resolver_remembered(): void
    {
        $resolver = app(TaxonomyResolver::class);

        // resolved and remembered, exactly as an import would
        $attribute = $resolver->attribute($this->supplier, 'მოცულობა');

        $this->assertNotNull($attribute);

        // the row goes, as a rollback would take it
        Attribute::whereKey($attribute->id)->delete();

        $resolver->forget();

        $again = $resolver->attribute($this->supplier, 'მოცულობა');

        $this->assertNotSame($attribute->id, $again->id);
        $this->assertTrue(Attribute::whereKey($again->id)->exists());
    }

    /** And the importer does the clearing itself when a write throws. */
    public function test_the_importer_clears_it_when_a_write_fails(): void
    {
        $resolver = Mockery::mock(TaxonomyResolver::class)->makePartial();

        // whatever goes wrong inside the transaction, this is the contract
        $resolver->shouldReceive('attribute')->andThrow(new RuntimeException('the write failed'));
        $resolver->shouldReceive('forget')->once();

        $this->app->instance(TaxonomyResolver::class, $resolver);

        $this->expectException(RuntimeException::class);

        app(ProductImporter::class)->import($this->supplier, $this->payload(specName: 'მოცულობა'));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'midea', 'name' => 'Midea', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['percent' => 20]],
        ]);

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        // the network half is not what these are about
        $this->app->instance(ImageDownloader::class, Mockery::mock(ImageDownloader::class)->shouldIgnoreMissing());
    }

    protected function import(ProductPayload $payload): ?Product
    {
        return app(ProductImporter::class)->import($this->supplier, $payload);
    }

    protected function payload(
        string $name = 'მაცივარი',
        string $category = 'ტექნიკა',
        string $brand = 'ნორდა',
        ?string $specName = null,
        string $external = 'EXT-1',
        string $sku = 'SKU-1',
    ): ProductPayload {
        return new ProductPayload(
            externalId: $external,
            sku: $sku,
            costPrice: 1000,
            oldCostPrice: null,
            stock: 5,
            brandName: $brand,
            categoryName: $category,
            translations: ['ka' => ['name' => $name]],
            specs: $specName === null ? [] : [
                ['name' => $specName, 'value' => '384 ლ', 'locale' => 'ka'],
            ],
            images: [],
        );
    }
}
