<?php

namespace Tests\Feature;

use App\Livewire\Admin\Mapping\Index;
use App\Models\Attribute;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Re-pointing an attribute mapping takes the catalogue with it.
 *
 * Categories had relink and attributes had nothing, so mapping a supplier's
 * wording onto our own attribute only affected products imported afterwards.
 * Everything already imported stayed on the attribute the importer had
 * invented, which left the same parameter in the filter list twice.
 */
class AttributeRemapTest extends TestCase
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
            'code' => 'remap', 'name' => 'Remap', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['percent' => 10]],
        ]);

        $this->app->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = 8): void {}
        });
    }

    public function test_an_auto_mapping_is_flagged_for_review(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $this->assertDatabaseHas('supplier_attribute_map', [
            'supplier_id' => $this->supplier->id,
            'external_name' => 'მწარმოებელი',
            'is_auto' => true,
        ]);
    }

    public function test_the_new_parameters_filter_shows_only_unreviewed_rows(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal', 'მოდელი' => 'FV8066']);

        DB::table('supplier_attribute_map')->where('external_name', 'მოდელი')->update(['is_auto' => false]);

        // asserted on the rows, not the rendered text: every attribute also
        // appears in each row's target dropdown, reviewed or not
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->set('stateFilter', 'new')
            ->assertViewHas('rows', fn ($rows) => collect($rows->items())->pluck('external_name')->all() === ['მწარმოებელი'])
            ->assertViewHas('pending', fn ($p) => $p['attributes_new'] === 1);
    }

    /** Assigning is a decision, so the row stops being a guess. */
    public function test_assigning_clears_the_review_flag(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $brand = $this->attribute('our-brand');
        $row = DB::table('supplier_attribute_map')->where('external_name', 'მწარმოებელი')->first();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->call('assign', $row->id, $brand->id);

        $this->assertDatabaseHas('supplier_attribute_map', [
            'id' => $row->id, 'attribute_id' => $brand->id, 'is_auto' => false,
        ]);
    }

    /** The spec the importer already wrote moves onto the chosen attribute. */
    public function test_existing_specs_move_to_the_new_attribute(): void
    {
        $product = $this->import(['მწარმოებელი' => 'Tefal']);

        $invented = Attribute::where('code', 'mtsarmoebeli')->firstOrFail();
        $brand = $this->attribute('our-brand');
        $row = DB::table('supplier_attribute_map')->where('external_name', 'მწარმოებელი')->first();

        $this->assertDatabaseHas('product_specs', ['product_id' => $product->id, 'attribute_id' => $invented->id]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->call('assign', $row->id, $brand->id);

        $this->assertDatabaseMissing('product_specs', ['product_id' => $product->id, 'attribute_id' => $invented->id]);
        $this->assertDatabaseHas('product_specs', ['product_id' => $product->id, 'attribute_id' => $brand->id]);

        // the value travelled with it
        $spec = ProductSpec::where('product_id', $product->id)->where('attribute_id', $brand->id)->first();
        $this->assertSame('Tefal', $spec->value);
    }

    /**
     * (product_id, attribute_id) is unique, so a product already carrying the
     * target attribute cannot simply take a second row.
     */
    public function test_a_product_already_on_the_target_attribute_is_merged_not_duplicated(): void
    {
        $product = $this->import(['მწარმოებელი' => 'Tefal']);

        $brand = $this->attribute('our-brand');
        ProductSpec::create(['product_id' => $product->id, 'attribute_id' => $brand->id, 'sort_order' => 50])
            ->saveTranslations(['ka' => ['value' => 'ძველი მნიშვნელობა']]);

        $row = DB::table('supplier_attribute_map')->where('external_name', 'მწარმოებელი')->first();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->call('assign', $row->id, $brand->id);

        $this->assertSame(1, ProductSpec::where('product_id', $product->id)
            ->where('attribute_id', $brand->id)->count());

        // the supplier's value wins, because that is what the import maintains
        $spec = ProductSpec::where('product_id', $product->id)->where('attribute_id', $brand->id)->first();
        $this->assertSame('Tefal', $spec->value);
    }

    /** Another supplier may map the same wording elsewhere; its products stay put. */
    public function test_another_suppliers_products_are_left_alone(): void
    {
        $mine = $this->import(['მწარმოებელი' => 'Tefal']);

        $other = Supplier::create([
            'code' => 'other', 'name' => 'Other', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'markup' => [['percent' => 10]],
        ]);
        $theirs = $this->import(['მწარმოებელი' => 'Bosch'], $other, 'EXT-2');

        $invented = Attribute::where('code', 'mtsarmoebeli')->firstOrFail();
        $brand = $this->attribute('our-brand');
        $row = DB::table('supplier_attribute_map')
            ->where('supplier_id', $this->supplier->id)->where('external_name', 'მწარმოებელი')->first();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('tab', 'attributes')
            ->call('assign', $row->id, $brand->id);

        $this->assertDatabaseHas('product_specs', ['product_id' => $mine->id, 'attribute_id' => $brand->id]);
        $this->assertDatabaseHas('product_specs', ['product_id' => $theirs->id, 'attribute_id' => $invented->id]);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function attribute(string $code): Attribute
    {
        return Attribute::create([
            'code' => $code, 'type' => 'select',
            'is_filterable' => true, 'is_variant' => false, 'sort_order' => 1,
        ]);
    }

    /** @param  array<string, string>  $specs  name => value */
    protected function import(array $specs, ?Supplier $supplier = null, string $externalId = 'EXT-1'): Product
    {
        $supplier ??= $this->supplier;
        $rows = [];

        foreach ($specs as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value, 'locale' => 'ka', 'key' => false, 'filterable' => true];
        }

        return app(ProductImporter::class)->import($supplier, new ProductPayload(
            externalId: $externalId,
            sku: $supplier->code.'-'.$externalId,
            costPrice: 100,
            oldCostPrice: null,
            stock: 1,
            brandName: null,
            categoryName: null,
            translations: ['ka' => ['name' => 'ტესტი '.$externalId]],
            specs: $rows,
            images: [],
        ));
    }
}
