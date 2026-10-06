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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Handing the attribute mapping back to a person.
 *
 * The importer invents an attribute for every name it does not know, which is
 * what keeps specs from being lost — and what fills the taxonomy with one
 * attribute per supplier's wording. Starting over by hand means undoing
 * exactly that, and nothing a person made themselves.
 */
class ResetAttributeMappingsTest extends TestCase
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

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $this->artisan('attributes:reset')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('supplier_attribute_map')->whereNotNull('attribute_id')->count());
        $this->assertSame(1, Attribute::count());
    }

    public function test_it_unmaps_the_names_and_deletes_the_invented_attributes(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal', 'მოდელი' => 'FV8066']);

        $this->artisan('attributes:reset --force')->assertSuccessful();

        $this->assertSame(2, DB::table('supplier_attribute_map')->count(), 'the names themselves stay');
        $this->assertSame(0, DB::table('supplier_attribute_map')->whereNotNull('attribute_id')->count());
        $this->assertSame(0, Attribute::count());
        $this->assertSame(0, DB::table('product_specs')->count(), 'specs cascade with their attribute');
    }

    /** An attribute a person made is not the importer's to remove. */
    public function test_a_hand_made_attribute_survives(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $mine = Attribute::create([
            'code' => 'my-own-brand', 'type' => 'select',
            'is_filterable' => true, 'is_variant' => false, 'sort_order' => 1,
        ]);

        $this->artisan('attributes:reset --force')->assertSuccessful();

        $this->assertNotNull($mine->fresh());
        $this->assertSame(1, Attribute::count());
    }

    /** The safe path: re-point future imports, keep what the catalogue shows today. */
    public function test_keep_attributes_clears_the_mappings_only(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $this->artisan('attributes:reset --force --keep-attributes')->assertSuccessful();

        $this->assertSame(0, DB::table('supplier_attribute_map')->whereNotNull('attribute_id')->count());
        $this->assertSame(1, Attribute::count(), 'the attribute stays');
        $this->assertSame(1, DB::table('product_specs')->count(), 'and so does the spec on the product');
    }

    public function test_the_supplier_option_limits_what_is_reset(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);

        $other = Supplier::create([
            'code' => 'zoommer', 'name' => 'Z', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'markup' => [['percent' => 10]],
        ]);
        $this->import(['ეკრანი' => '55"'], $other, 'EXT-2');

        $this->artisan('attributes:reset --supplier=elite --force --keep-attributes')->assertSuccessful();

        $this->assertNull(DB::table('supplier_attribute_map')
            ->where('supplier_id', $this->supplier->id)->value('attribute_id'));

        $this->assertNotNull(DB::table('supplier_attribute_map')
            ->where('supplier_id', $other->id)->value('attribute_id'),
            "another supplier's mapping must be left alone");
    }

    /**
     * A mapping a person made is respected by the next import.
     *
     * This is what makes the whole exercise worth doing, and the reason the
     * command says to map before re-importing: a name left unmapped gets a
     * fresh invented attribute and the reset is undone.
     */
    public function test_a_hand_made_mapping_survives_the_next_import(): void
    {
        $this->import(['მწარმოებელი' => 'Tefal']);
        $this->artisan('attributes:reset --force')->assertSuccessful();

        $mine = Attribute::create([
            'code' => 'brand', 'type' => 'select',
            'is_filterable' => true, 'is_variant' => false, 'sort_order' => 1,
        ]);
        DB::table('supplier_attribute_map')->where('external_name', 'მწარმოებელი')
            ->update(['attribute_id' => $mine->id]);

        // a fresh worker, because the resolver remembers its answers
        $this->app->forgetInstance(TaxonomyResolver::class);

        $this->import(['მწარმოებელი' => 'Bosch']);

        $this->assertSame($mine->id, (int) DB::table('supplier_attribute_map')
            ->where('external_name', 'მწარმოებელი')->value('attribute_id'));

        $this->assertSame(1, Attribute::count(), 'no second attribute was invented');
        $this->assertSame(1, DB::table('product_specs')->where('attribute_id', $mine->id)->count());
    }

    /**
     * Scoping by category reaches the attributes its products carry.
     *
     * Nothing links an attribute to a category directly, so this is the only
     * meaning the option can have: category -> products -> specs -> attribute.
     */
    public function test_the_category_option_limits_what_is_reset(): void
    {
        $phones = $this->category('phones');
        $tvs = $this->category('tvs');

        $this->importInto($phones, ['ეკრანის ზომა' => '6.1"'], 'P-1');
        $this->importInto($tvs, ['HDMI პორტები' => '3'], 'T-1');

        $this->artisan('attributes:reset --force --category=phones --keep-attributes')->assertSuccessful();

        $this->assertNull($this->mappingFor('ეკრანის ზომა'), "the phone category's name is unmapped");
        $this->assertNotNull($this->mappingFor('HDMI პორტები'), 'another category is left alone');
    }

    /**
     * A mapping is one row for the whole shop.
     *
     * An attribute two categories share cannot be unmapped for one of them, so
     * the command says how many it is about to affect elsewhere rather than
     * pretending the scope is tighter than it is.
     */
    public function test_a_shared_attribute_is_reported_and_reset(): void
    {
        $phones = $this->category('phones');
        $tvs = $this->category('tvs');

        $this->importInto($phones, ['მწარმოებელი' => 'Xiaomi'], 'P-1');
        $this->importInto($tvs, ['მწარმოებელი' => 'LG'], 'T-1');

        $this->artisan('attributes:reset --category=phones')
            ->expectsOutputToContain('used by other categories too')
            ->assertSuccessful();

        $this->artisan('attributes:reset --force --category=phones --keep-attributes')->assertSuccessful();

        $this->assertNull($this->mappingFor('მწარმოებელი'));
    }

    /** --only-exclusive keeps the ones another category would lose. */
    public function test_only_exclusive_leaves_shared_attributes_mapped(): void
    {
        $phones = $this->category('phones');
        $tvs = $this->category('tvs');

        $this->importInto($phones, ['მწარმოებელი' => 'Xiaomi', 'ეკრანის ზომა' => '6.1"'], 'P-1');
        $this->importInto($tvs, ['მწარმოებელი' => 'LG'], 'T-1');

        $this->artisan('attributes:reset --force --category=phones --only-exclusive --keep-attributes')
            ->assertSuccessful();

        $this->assertNotNull($this->mappingFor('მწარმოებელი'), 'shared with the televisions, so kept');
        $this->assertNull($this->mappingFor('ეკრანის ზომა'), 'only the phones use it, so reset');
    }

    public function test_an_unknown_category_fails(): void
    {
        $this->artisan('attributes:reset --category=nothing-like-this')->assertFailed();
    }

    public function test_an_unknown_supplier_fails(): void
    {
        $this->artisan('attributes:reset --supplier=nobody')->assertFailed();
    }

    /* ------------------------------------------------------------------ helpers */

    /** @param  array<string, string>  $specs */
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

    protected function category(string $slug): Category
    {
        $category = Category::create(['is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => $slug, 'slug' => $slug]]);

        return $category->fresh();
    }

    protected function mappingFor(string $name): ?int
    {
        $id = DB::table('supplier_attribute_map')->where('external_name', $name)->value('attribute_id');

        return $id === null ? null : (int) $id;
    }

    /** @param  array<string, string>  $specs */
    protected function importInto(Category $category, array $specs, string $externalId): Product
    {
        $product = $this->import($specs, null, $externalId);
        $product->update(['category_id' => $category->id]);

        return $product->fresh();
    }
}
