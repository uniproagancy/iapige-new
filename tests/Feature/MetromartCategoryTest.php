<?php

namespace Tests\Feature;

use App\Services\Import\Drivers\Metromart\MetromartClient;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Metromart products arrived with no category, and none reached the mapping
 * queue.
 *
 * Its price list is three columns — a model, a cost and a quantity — and not
 * one of them says what the thing is, so categoryName was always null. The
 * resolver only parks a name it is actually given, so the admin's queue stayed
 * empty and every product sat uncategorised with nothing to map it by. The
 * same hole Kontakt and INGCO fell into; the answer is the same, the page's
 * own trail.
 *
 * Odoo spells that trail three ways depending on the theme and the version,
 * so all three are read — which is the part worth pinning, because which one
 * a given shop uses is not ours to decide.
 */
class MetromartCategoryTest extends TestCase
{
    /* ------------------------------------------------------------------ the three spellings */

    public function test_the_structured_breadcrumb_is_read(): void
    {
        $html = $this->page($this->structured(['მთავარი', 'მაღაზია', 'მაცივრები']));

        $this->assertSame('მაცივრები', $this->category($html));
    }

    public function test_a_rendered_list_is_read(): void
    {
        $html = $this->page('<ol class="breadcrumb">'
            .'<li><a href="/">მთავარი</a></li>'
            .'<li><a href="/shop">მაღაზია</a></li>'
            .'<li><a href="/shop/category/x">მაცივრები</a></li>'
            .'</ol>');

        $this->assertSame('მაცივრები', $this->category($html));
    }

    public function test_bare_microdata_is_read(): void
    {
        $html = $this->page('<div itemprop="breadcrumb">'
            .'<a href="/">მთავარი</a><a href="/shop/category/x">მაცივრები</a>'
            .'</div>');

        $this->assertSame('მაცივრები', $this->category($html));
    }

    /** The structured one wins, because it says what it means. */
    public function test_the_structured_one_is_preferred(): void
    {
        $html = $this->page(
            $this->structured(['მთავარი', 'სწორი'])
            .'<ol class="breadcrumb"><li><a href="/">მთავარი</a></li><li><a href="/x">არასწორი</a></li></ol>'
        );

        $this->assertSame('სწორი', $this->category($html));
    }

    /* ------------------------------------------------------------------ the edges */

    /** A trail that ends in the product itself has one step too many. */
    public function test_the_product_is_not_taken_for_a_category(): void
    {
        $html = $this->page($this->structured(['მთავარი', 'მაცივრები', 'მაცივარი LG GR-B509']));

        $this->assertSame('მაცივრები', $this->category($html));
    }

    /** Linked straight off the front page: there is no category to report. */
    public function test_a_trail_with_nothing_but_the_front_page_gives_null(): void
    {
        $this->assertNull($this->category($this->page($this->structured(['მთავარი']))));
    }

    public function test_the_shop_itself_is_not_a_category(): void
    {
        $this->assertNull($this->category($this->page($this->structured(['მთავარი', 'მაღაზია']))));
    }

    public function test_a_page_with_no_trail_gives_null(): void
    {
        $this->assertNull($this->category($this->page('')));
    }

    /** A deeper trail means a more specific category, which is the useful one. */
    public function test_the_deepest_step_wins(): void
    {
        $html = $this->page($this->structured(['მთავარი', 'ტექნიკა', 'სამზარეულო', 'მაცივრები']));

        $this->assertSame('მაცივრები', $this->category($html));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function category(string $html): ?string
    {
        $client = new MetromartClient;

        $xpath = new ReflectionMethod($client, 'xpath');
        $xpath->setAccessible(true);

        $method = new ReflectionMethod($client, 'category');
        $method->setAccessible(true);

        return $method->invoke($client, $html, $xpath->invoke($client, $html));
    }

    /** @param  array<int, string>  $names */
    protected function structured(array $names): string
    {
        $items = [];

        foreach ($names as $i => $name) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
        }

        return '<script type="application/ld+json">'
            .json_encode(['@type' => 'BreadcrumbList', 'itemListElement' => $items], JSON_UNESCAPED_UNICODE)
            .'</script>';
    }

    protected function page(string $body): string
    {
        return '<html><head>'.$body.'</head><body>'
            .'<h1 itemprop="name">მაცივარი LG GR-B509</h1>'
            .$body
            .'</body></html>';
    }
}
