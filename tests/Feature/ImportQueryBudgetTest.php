<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A ceiling on what one product costs the database.
 *
 * The importer runs tens of thousands of times a night, so a query added per
 * spec is a query added per spec per product — the kind of change that is
 * invisible in review and obvious at three in the morning. These budgets are
 * deliberately loose: they catch a new N+1, not a single extra statement.
 */
class ImportQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** 15 specs in 2 languages. Before the bulk rewrite: 168 and 105. */
    private const BUDGET_NEW = 45;

    private const BUDGET_UPDATE = 20;

    public function test_a_product_stays_within_its_query_budget(): void
    {
        [$supplier] = $this->catalogue();
        $importer = app(ProductImporter::class);

        // the first product of a run also creates the attributes; not measured
        $importer->import($supplier, $this->payload('warm-up'));

        $new = $this->countQueries(fn () => $importer->import($supplier, $this->payload('A-2')));
        $update = $this->countQueries(fn () => $importer->import($supplier, $this->payload('A-2')));

        $this->assertLessThanOrEqual(self::BUDGET_NEW, $new,
            "a new product now costs {$new} queries; it used to cost ".self::BUDGET_NEW.' or fewer');

        $this->assertLessThanOrEqual(self::BUDGET_UPDATE, $update,
            "re-importing a product now costs {$update} queries; it used to cost ".self::BUDGET_UPDATE.' or fewer');
    }

    protected function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array{0: Supplier, 1: Category} */
    protected function catalogue(): array
    {
        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'is_default' => false, 'sort_order' => 2],
        ]);
        Language::flushCache();

        $supplier = Supplier::create([
            'code' => 'budget', 'name' => 'Budget', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ლეპტოპები', 'slug' => 'laptops']]);

        DB::table('supplier_category_map')->insert([
            'supplier_id' => $supplier->id, 'external_name' => 'Laptops',
            'category_id' => $category->id, 'hits' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // downloading photographs is network work, measured nowhere near here
        $this->app->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = 8): void {}
        });

        return [$supplier, $category];
    }

    protected function payload(string $id): ProductPayload
    {
        $specs = [];

        for ($i = 1; $i <= 15; $i++) {
            foreach (['ka', 'en'] as $locale) {
                $specs[] = [
                    'name' => "Spec {$i}", 'value' => "Value {$i}", 'locale' => $locale,
                    'filterable' => $i <= 5, 'key' => $i <= 3,
                ];
            }
        }

        return new ProductPayload(
            externalId: $id,
            sku: 'SKU-'.$id,
            costPrice: 1000,
            oldCostPrice: 1200,
            stock: 5,
            brandName: 'NORDA',
            categoryName: 'Laptops',
            translations: [
                'ka' => ['name' => 'ლეპტოპი '.$id, 'summary' => 'შეჯამება'],
                'en' => ['name' => 'Laptop '.$id, 'summary' => 'Summary'],
            ],
            specs: $specs,
            images: ['https://example.com/1.jpg'],
            weight: 2000,
        );
    }
}
