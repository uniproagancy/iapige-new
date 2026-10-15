<?php

namespace Tests\Feature;

use App\Livewire\Admin\Import\Upload;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Models\User;
use App\Services\Import\ImportManager;
use App\Services\Import\PriceListLoader;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A price list is not always the supplier's whole catalogue.
 *
 * The loader treated every file as the whole truth, which is right for the
 * weekly list: a code missing from it has run out, and zeroing it is the only
 * way the shop stops offering it. It is wrong for the second file a supplier
 * sends covering a different part of their range — loading that took
 * everything the first file brought off sale, and the only sign of it was a
 * zeroed count on a screen nobody reads twice.
 *
 * So the file's meaning is now asked for rather than assumed. Replacing stays
 * the default, because it is what the weekly list is and because the mistake
 * in that direction is the recoverable one: the next full file puts it back.
 */
class PriceListMergeTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected string $directory;

    /** @var array<int, string> */
    protected array $written = [];

    /* ------------------------------------------------------------------ merging */

    /** The whole point: a code the second file does not mention keeps its stock. */
    public function test_merging_leaves_the_rows_it_does_not_mention(): void
    {
        $this->load([['A-1', '150', '10'], ['A-2', '220', '5']]);

        $this->load([['B-1', '300', '7']], PriceListLoader::MODE_MERGE);

        $this->assertSame(10, $this->quantity('A-1'));
        $this->assertSame(5, $this->quantity('A-2'));
        $this->assertSame(7, $this->quantity('B-1'));
    }

    /** A code the merged file does mention is still brought up to date. */
    public function test_merging_updates_what_it_does_mention(): void
    {
        $this->load([['A-1', '150', '10']]);

        $this->load([['A-1', '160', '2']], PriceListLoader::MODE_MERGE);

        $this->assertSame(2, $this->quantity('A-1'));
        $this->assertSame(160.0, (float) SupplierStock::where('external_id', 'A-1')->value('cost_price'));
    }

    /** Including down to nothing — a merged file may say a thing has run out. */
    public function test_a_merged_file_can_still_zero_a_row_by_naming_it(): void
    {
        $this->load([['A-1', '150', '10']]);

        $this->load([['A-1', '150', '0']], PriceListLoader::MODE_MERGE);

        $this->assertSame(0, $this->quantity('A-1'));
    }

    public function test_merging_counts_nothing_as_zeroed(): void
    {
        $this->load([['A-1', '150', '10']]);

        $result = $this->load([['B-1', '300', '7']], PriceListLoader::MODE_MERGE);

        $this->assertSame(0, $result['zeroed']);
        $this->assertSame(PriceListLoader::MODE_MERGE, $result['mode']);
    }

    /* ------------------------------------------------------------------ replacing */

    /** The old behaviour, unchanged and still the default. */
    public function test_replacing_zeroes_what_the_file_leaves_out(): void
    {
        $this->load([['A-1', '150', '10'], ['A-2', '220', '5']]);

        $result = $this->load([['A-1', '160', '8']]);

        $this->assertSame(8, $this->quantity('A-1'));
        $this->assertSame(0, $this->quantity('A-2'));
        $this->assertSame(1, $result['zeroed']);
        $this->assertSame(PriceListLoader::MODE_REPLACE, $result['mode']);
    }

    public function test_the_mode_is_replace_when_nobody_says_otherwise(): void
    {
        $result = (new PriceListLoader)->load($this->supplier, $this->path([['A-1', '150', '10']]));

        $this->assertSame(PriceListLoader::MODE_REPLACE, $result['mode']);
    }

    /** A file read in a mode nobody defined would do one of two things silently. */
    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        (new PriceListLoader)->load($this->supplier, $this->path([['A-1', '150', '10']]), 1, 1, 'append');
    }

    /** An empty file reports the mode too, so the screen can describe it. */
    public function test_a_file_with_no_rows_still_reports_the_mode(): void
    {
        $result = $this->load([], PriceListLoader::MODE_MERGE);

        $this->assertSame(0, $result['read']);
        $this->assertSame(PriceListLoader::MODE_MERGE, $result['mode']);
    }

    /* ------------------------------------------------------------------ what the shop sees */

    /**
     * The consequence the modes exist for.
     *
     * Zeroing the stock table is not what takes a product off sale — the next
     * import run is, when it settles the empty rows onto the offers. So the
     * two modes are worth following all the way there.
     */
    public function test_a_replaced_catalogue_takes_the_missing_product_off_sale(): void
    {
        $product = $this->productWithOffer('A-2', stock: 5);

        $this->load([['A-1', '150', '10'], ['A-2', '220', '5']]);
        $this->load([['A-1', '160', '8']]);

        $this->importRun();

        $this->assertSame(0, ProductOffer::where('external_id', 'A-2')->value('stock'));
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_a_merged_file_leaves_the_product_on_sale(): void
    {
        $product = $this->productWithOffer('A-2', stock: 5);

        $this->load([['A-1', '150', '10'], ['A-2', '220', '5']]);
        $this->load([['B-1', '300', '7']], PriceListLoader::MODE_MERGE);

        $this->importRun();

        $this->assertSame(5, ProductOffer::where('external_id', 'A-2')->value('stock'));
        $this->assertSame(5, $product->fresh()->stock);
    }

    /* ------------------------------------------------------------------ the screen */

    public function test_the_screen_replaces_unless_told_otherwise(): void
    {
        Livewire::test(Upload::class)->assertSet('mode', PriceListLoader::MODE_REPLACE);
    }

    public function test_the_screen_can_merge(): void
    {
        $screen = Livewire::test(Upload::class)->set('code', 'ingco');

        $screen->set('file', $this->csv([['code', 'price', 'stock'], ['A-1', '150', '10']]))
            ->call('load');

        $screen->set('mode', PriceListLoader::MODE_MERGE)
            ->set('file', $this->csv([['code', 'price', 'stock'], ['B-1', '300', '7']]))
            ->call('load')
            ->assertHasNoErrors();

        $this->assertSame(10, $this->quantity('A-1'));
        $this->assertSame(7, $this->quantity('B-1'));
    }

    /** It arrives from the browser like any other code, so it is checked. */
    public function test_a_mode_the_loader_does_not_know_is_refused(): void
    {
        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('mode', 'wipe')
            ->set('file', $this->csv([['code', 'price', 'stock'], ['A-1', '150', '10']]))
            ->call('load')
            ->assertHasErrors('mode');

        $this->assertSame(0, SupplierStock::count());
    }

    /**
     * Changing supplier puts it back.
     *
     * Merging is chosen for one particular file; carrying that choice onto
     * the next supplier's weekly list would quietly stop it clearing out.
     */
    public function test_choosing_another_supplier_returns_to_replacing(): void
    {
        Supplier::create([
            'code' => 'metromart', 'name' => 'Metromart', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['columns' => ['key' => 'A', 'cost' => 'B', 'stock' => 'C']],
        ]);

        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('mode', PriceListLoader::MODE_MERGE)
            ->set('code', 'metromart')
            ->assertSet('mode', PriceListLoader::MODE_REPLACE);
    }

    public function test_both_choices_are_offered(): void
    {
        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->assertSee(__('admin.import_mode_replace'))
            ->assertSee(__('admin.import_mode_merge'));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/price-list-'.uniqid();
        mkdir($this->directory);

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'ingco', 'name' => 'Ingco', 'driver' => NoFetchDriver::class, 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['columns' => ['key' => 'A', 'cost' => 'B', 'stock' => 'C']],
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * @param  array<int, array<int, string>>  $rows  without the header
     * @return array{read:int, zeroed:int, missing_url:int, no_stock_column:bool, mode:string}
     */
    protected function load(array $rows, string $mode = PriceListLoader::MODE_REPLACE): array
    {
        return (new PriceListLoader)->load($this->supplier, $this->path($rows), 1, 1, $mode);
    }

    /**
     * A real .csv on disk, kept until the test ends.
     *
     * Not an UploadedFile::fake(): its temporary file is deleted the moment
     * the object goes out of scope, and it carries no extension — so the
     * loader reached for the spreadsheet reader and found nothing there.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    protected function path(array $rows): string
    {
        $path = $this->directory.'/prices-'.count($this->written).'.csv';
        $lines = array_map(
            fn (array $row) => implode(',', $row),
            array_merge([['code', 'price', 'stock']], $rows),
        );

        file_put_contents($path, implode('
', $lines));
        $this->written[] = $path;

        return $path;
    }

    /** @param  array<int, array<int, string>>  $rows */
    protected function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn (array $row) => implode(',', $row), $rows);

        return UploadedFile::fake()->createWithContent('prices.csv', implode("\n", $lines));
    }

    protected function quantity(string $code): ?int
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)
            ->where('external_id', $code)
            ->value('quantity');
    }

    protected function productWithOffer(string $code, int $stock): Product
    {
        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);

        $product = Product::create([
            'sku' => 'SKU-'.$code,
            'category_id' => $category->id,
            'price' => 300,
            'stock' => $stock,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now()->subHour(),
        ]);

        ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'external_id' => $code,
            'cost_price' => 220,
            'stock' => $stock,
            'synced_at' => now(),
        ]);

        return $product;
    }

    /** The run only has to settle the empty rows; nothing here needs fetching. */
    protected function importRun(): void
    {
        app(ImportManager::class)->run($this->supplier->fresh());
    }
}

/** Queues nothing, so a run is only the settling step. */
class NoFetchDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return [];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}
