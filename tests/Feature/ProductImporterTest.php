<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpec;
use App\Models\Supplier;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The specs are written with a bulk upsert rather than a row at a time, so
 * these pin down what the catalogue must end up looking like either way.
 */
class ProductImporterTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'is_default' => false, 'sort_order' => 2],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'acme', 'name' => 'Acme', 'driver' => 'X', 'is_active' => true,
            'priority' => 1,
            // tiered, as the column really is: [{"up_to":n,"percent":n}]
            'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $this->category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'ლეპტოპები', 'slug' => 'laptops']]);

        DB::table('supplier_category_map')->insert([
            'supplier_id' => $this->supplier->id, 'external_name' => 'Laptops',
            'category_id' => $this->category->id, 'hits' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * The network half is covered by its own concerns, not by these — but
         * the fake does record a row per image, because whether the importer
         * asks again depends on whether the last attempt left anything behind.
         */
        $this->app->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public array $calls = [];

            /** When true the gallery fails, as a dead CDN would. */
            public bool $failing = false;

            public function sync(Product $product, array $urls, int $limit = 8): void
            {
                $this->calls[] = $product->id;

                if ($this->failing) {
                    return;
                }

                foreach (array_values($urls) as $i => $url) {
                    ProductImage::updateOrCreate(
                        ['product_id' => $product->id, 'sort_order' => $i],
                        ['path' => 'products/'.$product->id.'/'.$i.'.jpg'],
                    );
                }
            }
        });
    }

    public function test_it_writes_the_product_with_its_specs_and_translations(): void
    {
        $product = app(ProductImporter::class)->import($this->supplier, $this->payload());

        $this->assertNotNull($product);
        $this->assertSame('SKU-1', $product->sku);
        $this->assertSame($this->category->id, $product->category_id);
        $this->assertSame(5, $product->stock);

        // markup applied: 1000 + 20%
        $this->assertEquals(1200.0, (float) $product->price);

        $this->assertSame('ლეპტოპი', $product->translate('ka')->name);
        $this->assertSame('Laptop', $product->translate('en')->name);

        $specs = ProductSpec::where('product_id', $product->id)->with('translations')->get();
        $this->assertCount(2, $specs);

        $cpu = $specs->first(fn ($s) => $s->translations->contains('value', 'Ryzen 9'));
        $this->assertNotNull($cpu, 'the Georgian spec value was not written');
        $this->assertTrue($cpu->is_key);
        $this->assertSame('Ryzen 9', $cpu->translate('ka')->value);
        $this->assertSame('Ryzen 9', $cpu->translate('en')->value);

        /*
         * Current behaviour: EVERY spec becomes an attribute_value link, not
         * only the filterable ones — the class comment says otherwise. Pinned
         * here as it stands so the bulk rewrite is a like-for-like change.
         */
        $this->assertSame(2, $product->attributeValues()->count());
    }

    public function test_a_second_import_updates_rather_than_duplicates(): void
    {
        $importer = app(ProductImporter::class);

        $first = $importer->import($this->supplier, $this->payload());
        $second = $importer->import($this->supplier, $this->payload(cpu: 'Ryzen 7', stock: 9));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductSpec::where('product_id', $first->id)->count());
        $this->assertSame(1, DB::table('product_offers')->count());

        $cpu = ProductSpec::where('product_id', $first->id)->with('translations')->get()
            ->first(fn ($s) => $s->translate('ka')?->value === 'Ryzen 7');

        $this->assertNotNull($cpu, 'the changed spec value was not updated');
        $this->assertSame(9, $second->fresh()->stock);
    }

    /** The slug is the product's address; a re-import must not move it. */
    public function test_an_existing_slug_is_kept(): void
    {
        $importer = app(ProductImporter::class);

        $product = $importer->import($this->supplier, $this->payload());
        $slug = $product->translate('ka')->slug;

        $importer->import($this->supplier, $this->payload(name: 'სულ სხვა სახელი'));

        $this->assertSame($slug, $product->fresh()->translate('ka')->slug);
    }

    /** Photographs are fetched outside the transaction, and once is enough. */
    public function test_a_gallery_already_fetched_is_not_fetched_again(): void
    {
        $importer = app(ProductImporter::class);
        $downloader = app(ImageDownloader::class);

        $importer->import($this->supplier, $this->payload());
        $this->assertCount(1, $downloader->calls);
        $this->assertDatabaseCount('product_images', 1);

        $importer->import($this->supplier, $this->payload());
        $this->assertCount(1, $downloader->calls, 'an update must not re-download the gallery');
    }

    /**
     * A product left with no photographs is asked for them again.
     *
     * Images used to be fetched for new products only, so one timed-out CDN on
     * the first import meant a product that stayed blank however many times it
     * was re-imported — and nothing short of hand-editing would fix it.
     */
    public function test_a_gallery_that_failed_is_retried_on_the_next_run(): void
    {
        $importer = app(ProductImporter::class);
        $downloader = app(ImageDownloader::class);

        $downloader->failing = true;
        $importer->import($this->supplier, $this->payload());

        $this->assertCount(1, $downloader->calls);
        $this->assertDatabaseCount('product_images', 0);

        $downloader->failing = false;
        $importer->import($this->supplier, $this->payload());

        $this->assertCount(2, $downloader->calls, 'a product with no gallery must be asked again');
        $this->assertDatabaseCount('product_images', 1);
    }

    /** An unmapped category parks the name and leaves the product uncategorised. */
    public function test_an_unknown_category_is_parked(): void
    {
        $product = app(ProductImporter::class)->import($this->supplier, $this->payload(category: 'Unmapped'));

        $this->assertNull($product->category_id);
        $this->assertDatabaseHas('supplier_category_map', [
            'supplier_id' => $this->supplier->id, 'external_name' => 'Unmapped', 'category_id' => null,
        ]);
    }

    protected function payload(
        string $cpu = 'Ryzen 9',
        int $stock = 5,
        string $name = 'ლეპტოპი',
        string $category = 'Laptops',
    ): ProductPayload {
        return new ProductPayload(
            externalId: 'EXT-1',
            sku: 'SKU-1',
            costPrice: 1000,
            oldCostPrice: null,
            stock: $stock,
            brandName: 'NORDA',
            categoryName: $category,
            translations: [
                'ka' => ['name' => $name, 'summary' => 'შეჯამება'],
                'en' => ['name' => 'Laptop', 'summary' => 'Summary'],
            ],
            specs: [
                ['name' => 'CPU', 'value' => $cpu, 'locale' => 'ka', 'filterable' => true, 'key' => true],
                ['name' => 'CPU', 'value' => $cpu, 'locale' => 'en', 'filterable' => true, 'key' => true],
                ['name' => 'RAM', 'value' => '32GB', 'locale' => 'ka'],
                ['name' => 'RAM', 'value' => '32GB', 'locale' => 'en'],
            ],
            images: ['https://example.com/1.jpg'],
            weight: 2000,
        );
    }
}
