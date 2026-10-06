<?php

namespace Tests\Feature;

use App\Livewire\Admin\Mapping\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where a supplier's attribute name came from.
 *
 * Mapping "ეკრანის ზომა" by hand is guesswork without knowing whether it
 * arrived on a phone or a television. Nothing links an attribute to a category,
 * so the column answers the only way it can — attribute, specs, products,
 * category — and that chain is also why deleting the attributes takes the
 * context with it.
 */
class MappingCategoryColumnTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'elite', 'name' => 'Elite', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['percent' => 10]],
        ]);

        $this->app->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = 8): void {}
        });
    }

    public function test_it_names_the_category_an_attribute_came_from(): void
    {
        $phones = $this->category('ტელეფონები');
        $this->importInto($phones, ['ეკრანის ზომა' => '6.1"'], 'P-1');

        $context = $this->contextFor('attributes');

        $this->assertCount(1, $context);
        $this->assertSame('ტელეფონები', head(head($context))['name']);
        $this->assertSame(1, head(head($context))['products']);
    }

    /** A name used in two departments says so, busiest first. */
    public function test_several_categories_are_listed_busiest_first(): void
    {
        $phones = $this->category('ტელეფონები');
        $tvs = $this->category('ტელევიზორები');

        foreach (['P-1', 'P-2', 'P-3'] as $i => $id) {
            $this->importInto($phones, ['მწარმოებელი' => 'Xiaomi'], $id);
        }
        $this->importInto($tvs, ['მწარმოებელი' => 'LG'], 'T-1');

        $listed = head($this->contextFor('attributes'));

        $this->assertSame(['ტელეფონები', 'ტელევიზორები'], array_column($listed, 'name'));
        $this->assertSame([3, 1], array_column($listed, 'products'));
    }

    /** The column belongs to the attributes tab and nowhere else. */
    public function test_the_categories_tab_gets_no_context(): void
    {
        $phones = $this->category('ტელეფონები');
        $this->importInto($phones, ['ეკრანის ზომა' => '6.1"'], 'P-1');

        $this->assertSame([], $this->contextFor('categories'));
    }

    /** An unmapped row has no attribute, so there is no chain to follow. */
    public function test_an_unmapped_row_shows_nothing(): void
    {
        $phones = $this->category('ტელეფონები');
        $this->importInto($phones, ['ეკრანის ზომა' => '6.1"'], 'P-1');

        DB::table('supplier_attribute_map')->update(['attribute_id' => null]);

        $this->assertSame([], $this->contextFor('attributes'));
    }

    /**
     * One query for the page, however many rows are on it.
     *
     * A column that asked per row would be the kind of thing nobody notices
     * until the mapping screen is the slowest page in the admin.
     */
    public function test_the_column_does_not_cost_a_query_per_row(): void
    {
        $phones = $this->category('ტელეფონები');

        for ($i = 1; $i <= 12; $i++) {
            $this->importInto($phones, ["სპეცი {$i}" => 'V'], "P-{$i}");
        }

        $this->renderAttributes(5);   // warm whatever caches there are
        $small = $this->queriesToRender(5);
        $large = $this->queriesToRender(40);

        $this->assertSame($small, $large,
            "5 rows took {$small} queries and 40 took {$large}");
    }

    /* ------------------------------------------------------------------ helpers */

    protected function contextFor(string $tab): array
    {
        return Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', $tab)
            ->viewData('context');
    }

    protected function renderAttributes(int $perPage): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->set('perPage', $perPage)
            ->html();
    }

    protected function queriesToRender(int $perPage): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->renderAttributes($perPage);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function category(string $name): Category
    {
        $category = Category::create(['is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => $name, 'slug' => Str::random(8)]]);

        return $category->fresh();
    }

    /** @param  array<string, string>  $specs */
    protected function importInto(Category $category, array $specs, string $externalId): Product
    {
        $rows = [];

        foreach ($specs as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value, 'locale' => 'ka', 'key' => false, 'filterable' => true];
        }

        $product = app(ProductImporter::class)->import($this->supplier, new ProductPayload(
            externalId: $externalId,
            sku: 'elite-'.$externalId,
            costPrice: 100,
            oldCostPrice: null,
            stock: 1,
            brandName: null,
            categoryName: null,
            translations: ['ka' => ['name' => 'ტესტი '.$externalId]],
            specs: $rows,
            images: [],
        ));

        $product->update(['category_id' => $category->id]);

        return $product->fresh();
    }
}
