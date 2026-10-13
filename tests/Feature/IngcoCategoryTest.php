<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Ingco\IngcoDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INGCO products arrived with no category, and none reached the mapping queue.
 *
 * Its price list is four columns — model, two prices and a quantity — and not
 * one of them says what the thing is, so categoryName was always null. The
 * resolver only parks a name it is actually given, so the admin's queue stayed
 * empty and every INGCO product sat uncategorised with nothing to map it by.
 * The same hole Kontakt fell into; the answer is the same, the page's own
 * trail.
 *
 * Reading it turned up a second fault underneath. Only the first ld+json script
 * on the page was ever read, whatever it described — and a page that publishes
 * its breadcrumb before its product, which is the usual order, handed back the
 * breadcrumb. The product's name, description and availability were then read
 * off a node that has none of them.
 */
class IngcoCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the category */

    public function test_the_category_comes_from_the_structured_breadcrumb(): void
    {
        $this->fake($this->crumbs(['მთავარი', 'ხელსაწყოები', 'მტვერსასრუტები']));

        $this->assertSame('მტვერსასრუტები', $this->payload()->categoryName);
    }

    /** A deeper trail means a more specific category, which is the useful one. */
    public function test_the_deepest_step_wins(): void
    {
        $this->fake($this->crumbs(['მთავარი', 'ხელსაწყოები', 'დასუფთავება', 'მტვერსასრუტები']));

        $this->assertSame('მტვერსასრუტები', $this->payload()->categoryName);
    }

    /** The trail usually ends in the product, which is not a category. */
    public function test_the_product_itself_is_not_taken_for_a_category(): void
    {
        $this->fake($this->crumbs([
            'მთავარი', 'ხელსაწყოები', 'მტვერსასრუტები', 'მტვერსასრუტი 1400 ვტ INGCO VC14301',
        ]));

        $this->assertSame('მტვერსასრუტები', $this->payload()->categoryName);
    }

    /** A page with no structured breadcrumb still renders one. */
    public function test_a_rendered_breadcrumb_is_read(): void
    {
        $rendered = '<div class="breadcrumb"><ul>'
            .'<li><a href="/ka"><span>მთავარი</span></a></li>'
            .'<li><a href="/ka/tools"><span>ხელსაწყოები</span></a></li>'
            .'<li><a href="/ka/vacuums"><span>მტვერსასრუტები</span></a></li>'
            .'</ul></div>';

        $this->fake(extra: '', body: $rendered);

        $this->assertSame('მტვერსასრუტები', $this->payload()->categoryName);
    }

    /** Linked straight off the front page: there is no category to report. */
    public function test_a_trail_with_nothing_but_the_front_page_gives_null(): void
    {
        $this->fake($this->crumbs(['მთავარი']));

        $this->assertNull($this->payload()->categoryName);
    }

    public function test_a_page_with_no_trail_at_all_gives_null(): void
    {
        $this->fake();

        $this->assertNull($this->payload()->categoryName);
    }

    /* ------------------------------------------------------------------ the product node */

    /**
     * The product is still read from its own node.
     *
     * The breadcrumb is published first here, which is what used to be
     * mistaken for the product.
     */
    public function test_the_product_is_read_from_its_own_node(): void
    {
        $this->fake($this->crumbs(['მთავარი', 'მტვერსასრუტები']), breadcrumbFirst: true);

        $payload = $this->payload();

        $this->assertSame('მტვერსასრუტი 1400 ვტ INGCO VC14301', $payload->translations['ka']['name']);
        $this->assertSame('მძლავრი სამრეწველო მტვერსასრუტი', $payload->translations['ka']['description']);
        $this->assertSame('მტვერსასრუტები', $payload->categoryName);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'ingco',
            'name' => 'INGCO',
            'driver' => IngcoDriver::class,
            'is_active' => true,
            'priority' => 60,
            'markup' => [['up_to' => 200, 'percent' => 25], ['percent' => 20]],
            'config' => [
                'locale' => 'ka',
                'brand' => 'INGCO',
                'min_price' => 120,
                'min_stock' => 5,
                'columns' => ['key' => 'A', 'sale' => 'B', 'cost' => 'C', 'stock' => 'D'],
            ],
        ]);

        SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => '1007081',
            'quantity' => 15,
            'cost_price' => 450,
            'data' => ['sale' => '375'],
            'synced_at' => now(),
        ]);
    }

    protected function payload()
    {
        return (new IngcoDriver($this->supplier->fresh()))->fetch('1007081');
    }

    /** @param  array<int, string>  $names */
    protected function crumbs(array $names): string
    {
        $items = [];

        foreach ($names as $i => $name) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
        }

        return $this->ld(['@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }

    protected function ld(array $data): string
    {
        return '<script type="application/ld+json">'.json_encode($data, JSON_UNESCAPED_UNICODE).'</script>';
    }

    /** The autocomplete that finds the page, and the page itself. */
    protected function fake(string $extra = '', string $body = '', bool $breadcrumbFirst = false): void
    {
        $product = $this->ld([
            '@type' => 'Product',
            'name' => 'მტვერსასრუტი 1400 ვტ INGCO VC14301',
            'description' => 'მძლავრი სამრეწველო მტვერსასრუტი',
            'image' => ['https://ingco.ge/images/vc14301.jpg'],
            'offers' => ['price' => 450, 'availability' => 'https://schema.org/InStock'],
        ]);

        $head = $breadcrumbFirst ? $extra.$product : $product.$extra;

        $page = '<html><head>'.$head.'</head><body><h1>VC14301</h1>'.$body.'</body></html>';

        Http::fake(function ($request) use ($page) {
            return str_contains($request->url(), 'searchtermautocomplete')
                ? Http::response('<a href="/ka/vc14301">VC14301</a>', 200)
                : Http::response($page, 200);
        });
    }
}
