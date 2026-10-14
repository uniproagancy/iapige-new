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
        $this->assertSame('მაცივრის მოცულობა', Text::utf8('მაცივრის მოცულობა ,'.$this->half()));
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
            Text::utf8All(['მოცულობა'.$this->half() => 'x']),
        );
    }

    /* ------------------------------------------------------------------ the import */

    public function test_a_spec_with_a_broken_name_is_still_imported(): void
    {
        $product = $this->import($this->payload(specName: 'მაცივრის მოცულობა ,'.$this->half()));

        $this->assertNotNull($product);
        $this->assertSame(1, $product->specs()->count());
    }

    /** And the attribute it needed exists, spelled properly. */
    public function test_the_attribute_is_created_with_a_clean_name(): void
    {
        $this->import($this->payload(specName: 'მაცივრის მოცულობა ,'.$this->half()));

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
        $this->import($this->payload(specName: 'მაცივრის მოცულობა ,'.$this->half()));

        $this->assertDatabaseHas('supplier_attribute_map', [
            'supplier_id' => $this->supplier->id,
            'external_name' => 'მაცივრის მოცულობა',
        ]);
    }

    /** A broken product name must not reach the translations either. */
    public function test_a_broken_product_name_is_repaired(): void
    {
        $product = $this->import($this->payload(name: 'მაცივარი LG'.$this->half()));

        $this->assertSame('მაცივარი LG', $product->fresh()->name);
    }

    public function test_a_broken_category_name_is_repaired(): void
    {
        $this->import($this->payload(category: 'ტექნიკა'.$this->half()));

        $this->assertDatabaseHas('supplier_category_map', ['external_name' => 'ტექნიკა']);
    }

    public function test_a_broken_brand_name_is_repaired(): void
    {
        $product = $this->import($this->payload(brand: 'ნორდა'.$this->half()));

        $this->assertSame('ნორდა', $product->fresh()->brand->name);
    }

    /* ------------------------------------------------------------------ the last gate */

    /**
     * Nothing reaches a translation table broken, whoever wrote it.
     *
     * The import repairs its payloads, but translations are written from the
     * admin and from commands too — and the repair is only worth having if it
     * cannot be walked around.
     */
    public function test_a_translation_written_directly_is_repaired(): void
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $category->saveTranslations(['ka' => ['name' => 'საყინულ'.$this->half(), 'slug' => 'sayinuli']]);

        $this->assertSame('საყინულ', $category->fresh()->name);
    }

    /**
     * The real summary from the log: 309 bytes of Georgian ending two thirds
     * of the way through a letter.
     */
    public function test_a_long_broken_summary_is_repaired(): void
    {
        $summary = 'წყლის დისპენსერი,შავი,ქვედა ჩატვირთვის მექანიზმი,'
            .'ერთონკანიანი დიზაინი,მაღალი ონკანი დიდი ზომის ჭურჭლის ასავსება'.$this->half();

        $product = $this->import($this->payload(summary: $summary));

        $stored = $product->fresh()->translate('ka')->summary;

        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertStringEndsWith('ასავსება', $stored);
    }

    /** The mapping queue gets the name too, which is what an admin maps by. */
    public function test_a_broken_category_reaches_the_mapping_queue_clean(): void
    {
        $this->import($this->payload(category: 'საყინულ'.$this->half()));

        $this->assertDatabaseHas('supplier_category_map', [
            'supplier_id' => $this->supplier->id,
            'external_name' => 'საყინულ',
        ]);
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

    /**
     * Two thirds of a Georgian letter.
     *
     * Built from bytes rather than written as an escape: a source file cannot
     * hold an incomplete character, and a formatter that rewrites a
     * double-quoted escape into a single-quoted literal turns the broken bytes
     * into ordinary ones — repairing the very thing under test.
     */
    protected function half(): string
    {
        return chr(0xE1).chr(0x83);
    }

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
        ?string $summary = null,
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
            translations: ['ka' => array_filter(['name' => $name, 'summary' => $summary])],
            specs: $specName === null ? [] : [
                ['name' => $specName, 'value' => '384 ლ', 'locale' => 'ka'],
            ],
            images: [],
        );
    }
}
