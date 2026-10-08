<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Supplier;
use App\Services\Import\Drivers\Elite\EliteDriver;
use App\Services\Import\Drivers\Zoommer\ZoommerDriver;
use App\Services\Import\TaxonomyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mapping by subcategory as well as by category.
 *
 * One supplier category is often far too broad to be useful: a mouse mat and
 * a monitor stand both arrive as "Laptop accessories" and cannot both belong
 * where that maps to. The subcategory travels with it now, as a trail, and
 * the mapping screen offers the finer name.
 *
 * The trail must not cost us the mappings already made, so a name nobody has
 * mapped yet falls back to the broader one behind it.
 */
class CategoryTrailMappingTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Category $laptops;

    protected Category $mice;

    /* ------------------------------------------------------------------ joining */

    public function test_a_category_and_its_subcategory_become_one_trail(): void
    {
        $this->assertSame(
            'ლეპტოპები > გეიმინგ ლეპტოპები',
            TaxonomyResolver::trail('ლეპტოპები', 'გეიმინგ ლეპტოპები'),
        );
    }

    /** Not every product has one, and a dangling separator maps to nothing. */
    public function test_a_missing_subcategory_leaves_the_category_alone(): void
    {
        $this->assertSame('ლეპტოპები', TaxonomyResolver::trail('ლეპტოპები', null));
        $this->assertSame('ლეპტოპები', TaxonomyResolver::trail('ლეპტოპები', '  '));
        $this->assertNull(TaxonomyResolver::trail(null, null));
    }

    /* ------------------------------------------------------------------ resolving */

    /** The finer mapping wins when there is one. */
    public function test_the_subcategory_mapping_is_preferred(): void
    {
        $this->map('ლეპტოპები', $this->laptops);
        $this->map('ლეპტოპების აქსესუარები > მაუსები', $this->mice);

        $resolved = $this->resolve('ლეპტოპების აქსესუარები > მაუსები');

        $this->assertSame($this->mice->id, $resolved?->id);
    }

    /**
     * And the broader one carries it until the finer one exists.
     *
     * Otherwise introducing the trail would have unmapped every supplier
     * category at once, and the whole catalogue would have arrived with no
     * category the next time the import ran.
     */
    public function test_an_unmapped_trail_falls_back_to_its_category(): void
    {
        $this->map('ლეპტოპები', $this->laptops);

        $resolved = $this->resolve('ლეპტოპები > გეიმინგ ლეპტოპები');

        $this->assertSame($this->laptops->id, $resolved?->id);
    }

    /** Even so, the finer name is put in front of somebody. */
    public function test_the_unmapped_trail_still_reaches_the_mapping_screen(): void
    {
        $this->map('ლეპტოპები', $this->laptops);

        $this->resolve('ლეპტოპები > გეიმინგ ლეპტოპები');

        $this->assertDatabaseHas('supplier_category_map', [
            'supplier_id' => $this->supplier->id,
            'external_name' => 'ლეპტოპები > გეიმინგ ლეპტოპები',
            'category_id' => null,
        ]);
    }

    /** Nothing mapped at any level is still nothing, and still recorded. */
    public function test_a_wholly_unknown_trail_maps_to_nothing(): void
    {
        $this->assertNull($this->resolve('უცნობი > სრულიად უცნობი'));

        $this->assertDatabaseHas('supplier_category_map', [
            'external_name' => 'უცნობი > სრულიად უცნობი',
            'category_id' => null,
        ]);
    }

    /** A plain name without a trail behaves exactly as it did. */
    public function test_a_plain_category_name_still_works(): void
    {
        $this->map('ლეპტოპები', $this->laptops);

        $this->assertSame($this->laptops->id, $this->resolve('ლეპტოპები')?->id);
    }

    /* ------------------------------------------------------------------ drivers */

    public function test_zoommer_sends_the_subcategory(): void
    {
        $this->assertSame(
            'ლეპტოპები > გეიმინგ ლეპტოპები',
            $this->driverCategory(ZoommerDriver::class, [
                'categoryName' => 'ლეპტოპები',
                'subCategoryName' => 'გეიმინგ ლეპტოპები',
            ]),
        );
    }

    public function test_elite_sends_the_subcategory(): void
    {
        $this->assertSame(
            'ტელევიზორები > OLED',
            $this->driverCategory(EliteDriver::class, [
                'categoryName' => 'ტელევიზორები',
                'subCategoryName' => 'OLED',
            ]),
        );
    }

    /** A source that sends no subcategory is unaffected. */
    public function test_a_product_with_no_subcategory_sends_the_category_alone(): void
    {
        $this->assertSame(
            'ლეპტოპები',
            $this->driverCategory(ZoommerDriver::class, ['categoryName' => 'ლეპტოპები']),
        );
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
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => ZoommerDriver::class, 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $this->laptops = $this->category('ლეპტოპები', 'laptops');
        $this->mice = $this->category('მაუსები', 'mice');
    }

    protected function category(string $name, string $slug): Category
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => $name, 'slug' => $slug]]);

        return $category->fresh();
    }

    protected function map(string $externalName, Category $category): void
    {
        DB::table('supplier_category_map')->insert([
            'supplier_id' => $this->supplier->id,
            'external_name' => $externalName,
            'category_id' => $category->id,
            'hits' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function resolve(string $name): ?Category
    {
        return app(TaxonomyResolver::class)->category($this->supplier, $name);
    }

    /** @param  array<string, mixed>  $product */
    protected function driverCategory(string $driverClass, array $product): ?string
    {
        $driver = new $driverClass(new Supplier([
            'code' => 'x', 'driver' => $driverClass, 'config' => ['locales' => ['ka']],
        ]));

        $method = new \ReflectionMethod($driver, 'toPayload');
        $method->setAccessible(true);

        if ($driverClass === ZoommerDriver::class) {
            $payload = $method->invoke($driver, '1', [
                'ka' => ['product' => $product + ['name' => 'x', 'price' => 10]],
            ]);

            return $payload?->categoryName;
        }

        /*
         * Elite refuses anything its stock spreadsheet does not list, so the
         * barcode has to be in there before the payload is built at all.
         */
        $stockFile = new \ReflectionProperty($driver, 'stock');
        $stockFile->setAccessible(true);
        $stockFile->setValue($driver, ['B1' => 5]);

        $payload = $method->invoke($driver, '1', [
            'product' => $product + ['name' => 'x', 'price' => 10, 'barCode' => 'B1'],
        ]);

        return $payload?->categoryName;
    }
}
