<?php

namespace Tests\Feature;

use App\Livewire\Admin\Import\Links;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Models\User;
use App\Services\Import\Drivers\Citrus\CitrusDriver;
use App\Services\Import\ImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Adding products without opening a terminal.
 *
 * A handful of addresses is not a nightly import. Asking somebody to keep a
 * queue worker running for twenty products — and to open a terminal to start
 * it — costs more than the work itself, so the fetching happens on the screen.
 *
 * One product per request, because a page of forty addresses is forty seconds
 * and many hosts will not let a single request live that long. The count on
 * the screen is then honest rather than a guess.
 */
class ImportLinksScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ pasting */

    public function test_pasted_addresses_are_stored(): void
    {
        $this->screen()
            ->set('links', "https://citrus.ge/product/one\nhttps://citrus.ge/product/two")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, SupplierStock::where('supplier_id', $this->supplier->id)->count());
    }

    public function test_the_box_is_emptied_once_they_are_in(): void
    {
        $this->screen()
            ->set('links', 'https://citrus.ge/product/one')
            ->call('save')
            ->assertSet('links', '');
    }

    public function test_an_empty_box_is_refused(): void
    {
        $this->screen()->set('links', '')->call('save')->assertHasErrors('links');
    }

    /* ------------------------------------------------------------------ fetching */

    /** Starting does not fetch: the button answers, then the page works. */
    public function test_starting_counts_what_is_waiting(): void
    {
        $this->rows('one', 'two');

        $this->screen()
            ->call('start')
            ->assertSet('running', true)
            ->assertSet('total', 2)
            ->assertSet('done', 0);
    }

    public function test_one_request_fetches_one_product(): void
    {
        $this->fakeApi();
        $this->rows('one', 'two');

        $screen = $this->screen()->call('start')->call('fetchNext');

        $screen->assertSet('done', 1)->assertSet('running', true);

        $this->assertSame(1, Product::count());
    }

    public function test_it_stops_when_there_is_nothing_left(): void
    {
        $this->fakeApi();
        $this->rows('one');

        $this->screen()
            ->call('start')
            ->call('fetchNext')
            ->call('fetchNext')
            ->assertSet('running', false)
            ->assertSet('done', 1);
    }

    /** An address the API will not answer for is counted, not thrown. */
    public function test_a_failure_is_counted_and_the_rest_go_on(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->rows('one', 'two');

        $this->screen()
            ->call('start')
            ->call('fetchNext')
            ->assertSet('failed', 1)
            ->assertSet('running', true);
    }

    public function test_it_can_be_stopped(): void
    {
        $this->rows('one', 'two');

        $this->screen()->call('start')->call('stop')->assertSet('running', false);
    }

    public function test_nothing_waiting_does_not_start(): void
    {
        $this->screen()->call('start')->assertSet('running', false);
    }

    /* ------------------------------------------------------------------ what is left */

    /** An address we already hold a product for is finished. */
    public function test_a_product_already_held_is_not_fetched_again(): void
    {
        $this->fakeApi();
        $this->rows('one', 'two');

        $this->hold('one');

        $this->screen()->call('start')->assertSet('total', 1);
    }

    /** Unless the whole lot is being refreshed, which is how a change is picked up. */
    public function test_refreshing_covers_everything(): void
    {
        $this->rows('one', 'two');

        $this->hold('one');

        $this->screen()->call('start', true)->assertSet('total', 2);
    }

    /* ------------------------------------------------------------------ the list */

    public function test_an_address_can_be_dropped(): void
    {
        $this->rows('one');

        $this->screen()->call('forget', 'one');

        $this->assertSame(0, SupplierStock::where('supplier_id', $this->supplier->id)->count());
    }

    /** Only suppliers that have no price list belong on this screen. */
    public function test_a_spreadsheet_supplier_is_not_listed(): void
    {
        Supplier::create([
            'code' => 'ingco', 'name' => 'INGCO', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'config' => ['columns' => ['key' => 'A']],
        ]);

        $codes = $this->screen()->instance()->suppliers()->pluck('code')->all();

        $this->assertSame(['citrus'], $codes);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        $this->supplier = Supplier::create([
            'code' => 'citrus',
            'name' => 'Citrus',
            'driver' => CitrusDriver::class,
            'is_active' => true,
            'priority' => 70,
            'markup' => [],
            'config' => ['locale' => 'ka'],
        ]);

        // the network half of the gallery is not what these are about
        $this->app->instance(ImageDownloader::class, Mockery::mock(ImageDownloader::class)->shouldIgnoreMissing());
    }

    protected function screen()
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Links::class);
    }

    protected function rows(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            SupplierStock::create([
                'supplier_id' => $this->supplier->id,
                'external_id' => $slug,
                'quantity' => 1,
                'data' => ['slug' => $slug],
                'synced_at' => now(),
            ]);
        }
    }

    /** A product we already hold for that address. */
    protected function hold(string $slug): void
    {
        $product = Product::create([
            'sku' => 'citrus-'.$slug,
            'category_id' => Category::first()->id,
            'price' => 100,
            'stock' => 5,
            'status' => Product::STATUS_DRAFT,
        ]);

        ProductOffer::create([
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'external_id' => $slug,
            'cost_price' => 100,
            'stock' => 5,
            'synced_at' => now(),
        ]);
    }

    protected function fakeApi(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 1,
            'sku' => 'CS1',
            'name' => 'პროდუქტი',
            'price' => 100,
            'old_price' => 0,
            'stock' => 5,
            'stock_status' => 1,
            'manufacturer' => ['name' => 'MARAZZI'],
            'image' => 'https://media.citrus.ge/a.jpg',
            'large_images' => ['https://media.citrus.ge/a.jpg'],
            'images' => [],
            'details' => ['description' => 'აღწერა'],
            'attributes' => [],
            'breadcrumb' => [['name' => 'ტექნიკა', 'slug' => 'teqnika']],
        ], 200)]);
    }
}
