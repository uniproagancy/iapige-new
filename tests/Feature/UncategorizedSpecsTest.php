<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Metromart\MetromartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every Metromart specification announced that it had no group.
 *
 * The group heading goes in front of an attribute's name to tell two of the
 * same name apart — "ზომა & წონა — სიგანე" is a different thing from a
 * screen's width. But Odoo files everything nobody grouped under
 * "Uncategorized", and on that shop it is most of them, so the catalogue
 * filled with "Uncategorized — ფერი" and "Uncategorized — ტიპი". It reads as
 * our mistake because it is shown as ours.
 */
class UncategorizedSpecsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ reading them */

    public function test_a_real_group_still_leads_the_name(): void
    {
        $specs = $this->specs('ზომა & წონა', 'სიგანე (მმ)', '833');

        $this->assertSame('ზომა & წონა — სიგანე (მმ)', $specs[0]['name']);
    }

    public function test_uncategorized_is_not_a_group(): void
    {
        $specs = $this->specs('Uncategorized', 'ფერი', 'თეთრი');

        $this->assertSame('ფერი', $specs[0]['name']);
    }

    /** However they happen to spell it. */
    public function test_the_other_spellings_are_not_groups_either(): void
    {
        foreach (['Uncategorised', 'UNCATEGORIZED', 'Other', 'სხვა'] as $group) {
            $this->assertSame('ფერი', $this->specs($group, 'ფერი', 'თეთრი')[0]['name'], $group);
        }
    }

    public function test_a_table_with_no_heading_at_all_is_fine(): void
    {
        $this->assertSame('ფერი', $this->specs(null, 'ფერი', 'თეთრი')[0]['name']);
    }

    /* ------------------------------------------------------------------ the ones already written */

    /** With no attribute by the clean name, the prefixed one is corrected. */
    public function test_an_existing_attribute_is_renamed(): void
    {
        $attribute = $this->attribute('uncategorized-peri', 'Uncategorized — ფერი');

        $this->migrate();

        $this->assertSame('ფერი', $attribute->fresh()->name);
    }

    /**
     * Where the clean name already exists, the two become one.
     *
     * Another supplier may have written it properly all along, and leaving
     * both means the admin maps the same thing twice.
     */
    public function test_a_duplicate_is_merged_into_the_real_one(): void
    {
        $keep = $this->attribute('peri', 'ფერი');
        $duplicate = $this->attribute('uncategorized-peri', 'Uncategorized — ფერი');

        $this->migrate();

        $this->assertNull(Attribute::find($duplicate->id));
        $this->assertNotNull(Attribute::find($keep->id));
        $this->assertSame('ფერი', $keep->fresh()->name);
    }

    public function test_the_mapping_rows_follow_the_merge(): void
    {
        $keep = $this->attribute('peri', 'ფერი');
        $duplicate = $this->attribute('uncategorized-peri', 'Uncategorized — ფერი');

        $supplier = Supplier::create([
            'code' => 'metromart', 'name' => 'Metromart', 'driver' => 'X',
            'is_active' => true, 'priority' => 1,
        ]);

        DB::table('supplier_attribute_map')->insert([
            'supplier_id' => $supplier->id,
            'external_name' => 'Uncategorized — ფერი',
            'attribute_id' => $duplicate->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migrate();

        $this->assertSame($keep->id, (int) DB::table('supplier_attribute_map')->value('attribute_id'));
    }

    /** A product carrying both keeps one row, because the key allows only one. */
    public function test_a_product_carrying_both_ends_with_one(): void
    {
        $keep = $this->attribute('peri', 'ფერი');
        $duplicate = $this->attribute('uncategorized-peri', 'Uncategorized — ფერი');

        $product = $this->product();

        foreach ([$keep->id, $duplicate->id] as $id) {
            DB::table('product_specs')->insert([
                'product_id' => $product->id, 'attribute_id' => $id,
                'is_key' => false, 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->migrate();

        $this->assertSame(1, DB::table('product_specs')->where('product_id', $product->id)->count());
        $this->assertSame($keep->id, (int) DB::table('product_specs')->value('attribute_id'));
    }

    /** A product carrying only the duplicate keeps its specification. */
    public function test_a_product_carrying_only_the_duplicate_keeps_it(): void
    {
        $keep = $this->attribute('peri', 'ფერი');
        $duplicate = $this->attribute('uncategorized-peri', 'Uncategorized — ფერი');

        $product = $this->product();

        DB::table('product_specs')->insert([
            'product_id' => $product->id, 'attribute_id' => $duplicate->id,
            'is_key' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migrate();

        $this->assertSame($keep->id, (int) DB::table('product_specs')->value('attribute_id'));
    }

    /**
     * The supplier's own name is unique per supplier.
     *
     * The clean one is often already there — mapped by hand in the admin
     * before the import ever wrote the prefixed one — so renaming into it is
     * a duplicate key. That is how this failed the first time it was run
     * against the real database.
     */
    public function test_a_map_row_whose_clean_name_is_taken_is_dropped(): void
    {
        $supplier = $this->supplier();
        $byHand = $this->attribute('kabeli', 'კაბელის სიგრძე (მ)');
        $imported = $this->attribute('uncat-kabeli', 'Uncategorized — კაბელის სიგრძე (მ)');

        // what somebody mapped in the admin, and what the import wrote
        $this->map($supplier, 'კაბელის სიგრძე (მ)', $byHand->id);
        $this->map($supplier, 'Uncategorized — კაბელის სიგრძე (მ)', $imported->id);

        $this->migrate();

        $rows = DB::table('supplier_attribute_map')->where('supplier_id', $supplier->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('კაბელის სიგრძე (მ)', $rows[0]->external_name);
        $this->assertSame($byHand->id, (int) $rows[0]->attribute_id);
    }

    /** With the clean name free, the row keeps its mapping and loses the prefix. */
    public function test_a_map_row_with_a_free_name_is_renamed(): void
    {
        $supplier = $this->supplier();
        $attribute = $this->attribute('uncat-peri', 'Uncategorized — ფერი');

        $this->map($supplier, 'Uncategorized — ფერი', $attribute->id);

        $this->migrate();

        $row = DB::table('supplier_attribute_map')->where('supplier_id', $supplier->id)->first();

        $this->assertSame('ფერი', $row->external_name);
        $this->assertSame($attribute->id, (int) $row->attribute_id);
    }

    /** Two suppliers with the same prefixed name keep one row each. */
    public function test_each_supplier_is_tidied_on_its_own(): void
    {
        $first = $this->supplier('metromart');
        $second = $this->supplier('kontakt');
        $attribute = $this->attribute('uncat-peri', 'Uncategorized — ფერი');

        $this->map($first, 'Uncategorized — ფერი', $attribute->id);
        $this->map($second, 'Uncategorized — ფერი', $attribute->id);

        $this->migrate();

        $this->assertSame(2, DB::table('supplier_attribute_map')->count());
        $this->assertSame(0, DB::table('supplier_attribute_map')
            ->where('external_name', 'like', 'Uncategorized%')->count());
    }

    /** Run twice, it changes nothing the second time. */
    public function test_running_it_again_is_harmless(): void
    {
        $supplier = $this->supplier();
        $attribute = $this->attribute('uncat-peri', 'Uncategorized — ფერი');

        $this->map($supplier, 'Uncategorized — ფერი', $attribute->id);

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, DB::table('supplier_attribute_map')->count());
        $this->assertSame('ფერი', $attribute->fresh()->name);
    }

    /** Anything properly named is left exactly as it was. */
    public function test_a_properly_named_attribute_is_untouched(): void
    {
        $attribute = $this->attribute('sigane', 'ზომა & წონა — სიგანე (მმ)');

        $this->migrate();

        $this->assertSame('ზომა & წონა — სიგანე (მმ)', $attribute->fresh()->name);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();
    }

    /** @return array<int, array{name:string, value:string}> */
    protected function specs(?string $group, string $name, string $value): array
    {
        $heading = $group === null ? '' : '<tr><th colspan="2">'.$group.'</th></tr>';

        $html = '<html><body><section id="product_full_spec"><table>'
            .$heading
            .'<tr><td>'.$name.'</td><td>'.$value.'</td></tr>'
            .'</table></section></body></html>';

        $client = new MetromartClient;

        $xpath = new ReflectionMethod($client, 'xpath');
        $xpath->setAccessible(true);

        $specs = new ReflectionMethod($client, 'specs');
        $specs->setAccessible(true);

        return $specs->invoke($client, $xpath->invoke($client, $html));
    }

    protected function attribute(string $code, string $name): Attribute
    {
        $attribute = Attribute::create([
            'code' => $code, 'type' => 'select',
            'is_filterable' => false, 'is_variant' => false, 'sort_order' => 100,
        ]);

        $attribute->saveTranslations(['ka' => ['name' => $name]]);

        return $attribute->fresh();
    }

    protected function supplier(string $code = 'metromart'): Supplier
    {
        return Supplier::create([
            'code' => $code, 'name' => ucfirst($code), 'driver' => 'X',
            'is_active' => true, 'priority' => 1,
        ]);
    }

    protected function map(Supplier $supplier, string $name, int $attributeId): void
    {
        DB::table('supplier_attribute_map')->insert([
            'supplier_id' => $supplier->id,
            'external_name' => $name,
            'attribute_id' => $attributeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function product(): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        return Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $category->id,
            'price' => 100, 'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    protected function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_15_120000_strip_uncategorized_from_attribute_names.php');

        $migration->up();
    }
}
