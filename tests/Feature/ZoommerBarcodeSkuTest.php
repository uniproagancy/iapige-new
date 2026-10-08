<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Zoommer\ZoommerDriver;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sku is the barcode on the box.
 *
 * It used to be "ZOOM-" and the source's own id, which is unique but means
 * nothing anywhere else — so the same article bought from two suppliers
 * became two products. The barcode is the same number wherever it is sold,
 * which is what lets one product collect an offer from each of them.
 *
 * The catch is that one model's colour variants share a barcode, and the
 * column is unique. These pin down both halves.
 */
class ZoommerBarcodeSkuTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ driver */

    public function test_the_barcode_becomes_the_sku(): void
    {
        $this->assertSame('6931474700000', $this->skuFor(['barCode' => '6931474700000']));
    }

    /** Whitespace around it is the source's, not part of the number. */
    public function test_the_barcode_is_trimmed(): void
    {
        $this->assertSame('6931474700000', $this->skuFor(['barCode' => '  6931474700000 ']));
    }

    /** Not every row has one, and those still need something unique. */
    public function test_a_missing_barcode_falls_back_to_the_source_id(): void
    {
        $this->assertSame('ZOOM-54500', $this->skuFor([]));
        $this->assertSame('ZOOM-54500', $this->skuFor(['barCode' => '']));
        $this->assertSame('ZOOM-54500', $this->skuFor(['barCode' => null]));
    }

    /* ------------------------------------------------------------------ importer */

    /**
     * Two colours of one phone, one barcode.
     *
     * Both keep the real number — the column is no longer unique, because a
     * barcode identifies an article and not a listing. They still have to
     * stay apart: the black one's photographs do not belong on the silver
     * one, and the supplier's own id in product_offers is what separates them.
     */
    public function test_colour_variants_sharing_a_barcode_both_import(): void
    {
        $importer = app(ProductImporter::class);

        $importer->import($this->supplier, $this->payload('ZM-1', '6931474700000'));
        $importer->import($this->supplier, $this->payload('ZM-2', '6931474700000'));

        $this->assertSame(2, Product::count());
        $this->assertSame(['6931474700000', '6931474700000'], Product::orderBy('id')->pluck('sku')->all());

        $this->assertSame(
            ['ZM-1', 'ZM-2'],
            Product::orderBy('id')->get()->map(fn ($p) => $p->offers->first()->external_id)->all(),
        );
    }

    /**
     * The same barcode from another supplier is the same article.
     *
     * This is the whole reason for the change: one product, two offers, and
     * the cheaper one wins — instead of the shop listing it twice.
     */
    public function test_another_supplier_with_the_same_barcode_joins_the_product(): void
    {
        $other = Supplier::create([
            'code' => 'elite', 'name' => 'Elite', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $importer = app(ProductImporter::class);

        $importer->import($this->supplier, $this->payload('ZM-1', '6931474700000'));
        $importer->import($other, $this->payload('EL-9', '6931474700000'));

        $this->assertSame(1, Product::count());
        $this->assertSame(2, Product::first()->offers()->count());
    }

    /** A re-import is the same offer again, not a second product. */
    public function test_reimporting_the_same_product_changes_nothing(): void
    {
        $importer = app(ProductImporter::class);

        $importer->import($this->supplier, $this->payload('ZM-1', '6931474700000'));
        $importer->import($this->supplier, $this->payload('ZM-1', '6931474700000'));

        $this->assertSame(1, Product::count());
        $this->assertSame('6931474700000', Product::first()->sku);
    }

    /**
     * An existing product keeps the sku it was given.
     *
     * The id pair finds it first, so a re-import never rewrites the number a
     * human may already have printed on a shelf label or an invoice.
     */
    public function test_an_existing_sku_is_not_rewritten(): void
    {
        $importer = app(ProductImporter::class);

        $importer->import($this->supplier, $this->payload('ZM-1', 'ZOOM-54500'));
        $importer->import($this->supplier, $this->payload('ZM-1', '6931474700000'));

        $this->assertSame(1, Product::count());
        $this->assertSame('ZOOM-54500', Product::first()->sku);
    }

    /* ------------------------------------------------------------------ helpers */

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => ZoommerDriver::class, 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტელეფონები', 'slug' => 'phones']]);

        // the network half belongs to its own tests
        $this->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = self::MAX_IMAGES): void {}
        });
    }

    /** @param  array<string, mixed>  $product */
    protected function skuFor(array $product): string
    {
        $driver = new ZoommerDriver(new Supplier([
            'code' => 'zoommer', 'driver' => ZoommerDriver::class, 'config' => ['locales' => ['ka']],
        ]));

        $method = new \ReflectionMethod($driver, 'sku');
        $method->setAccessible(true);

        return $method->invoke($driver, '54500', $product);
    }

    protected function payload(string $externalId, string $sku): ProductPayload
    {
        return new ProductPayload(
            externalId: $externalId,
            sku: $sku,
            costPrice: 1000,
            oldCostPrice: null,
            stock: 5,
            brandName: 'Lenovo',
            categoryName: 'Phones',
            translations: ['ka' => ['name' => 'ტელეფონი '.$externalId]],
        );
    }
}
