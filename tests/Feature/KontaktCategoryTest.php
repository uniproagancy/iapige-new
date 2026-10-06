<?php

namespace Tests\Feature;

use App\Services\Import\Drivers\Kontakt\KontaktClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kontakt products arrived with no category at all.
 *
 * The price list has no category column — only a code, a link, a price and a
 * quantity — so categoryName was always null. The resolver only parks a name it
 * is actually given, so the admin's mapping queue stayed empty and every Kontakt
 * product sat uncategorised with no way to fix it in bulk. The page's own
 * breadcrumb is where the answer was all along.
 */
class KontaktCategoryTest extends TestCase
{
    public function test_the_category_comes_from_the_breadcrumb(): void
    {
        $this->fakePage($this->breadcrumb(['მთავარი', 'უთო', 'უთო Tefal FV8066E0']));

        $this->assertSame('უთო', $this->page()['category']);
    }

    /** A deeper trail means a more specific category, which is the useful one. */
    public function test_the_deepest_category_wins(): void
    {
        $this->fakePage($this->breadcrumb([
            'მთავარი', 'ტექნიკა', 'სამზარეულო', 'მაცივრები', 'მაცივარი LG GR-B509',
        ]));

        $this->assertSame('მაცივრები', $this->page()['category']);
    }

    /** Linked straight off the front page: there is no category to report. */
    public function test_a_trail_with_no_category_gives_null(): void
    {
        $this->fakePage($this->breadcrumb(['მთავარი', 'უთო Tefal FV8066E0']));

        $this->assertNull($this->page()['category']);
    }

    public function test_a_page_with_no_breadcrumb_gives_null(): void
    {
        $this->fakePage('');

        $this->assertNull($this->page()['category']);
    }

    /** The breadcrumb must never be mistaken for the product itself. */
    public function test_the_product_is_still_read_from_its_own_node(): void
    {
        $this->fakePage($this->breadcrumb(['მთავარი', 'უთო', 'უთო Tefal FV8066E0']));

        $page = $this->page();

        $this->assertSame('უთო Tefal FV8066E0', $page['name']);
        $this->assertSame('Tefal', $page['brand']);
        $this->assertSame(359.99, $page['price']);
        $this->assertTrue($page['in_stock']);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function page(): array
    {
        return (new KontaktClient)->page('https://kontakt.ge/uto-tefal-fv8066e0');
    }

    /** @param  array<int, string>  $names */
    protected function breadcrumb(array $names): string
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

    protected function fakePage(string $extraJsonLd): void
    {
        $product = $this->ld([
            '@type' => 'Product',
            'name' => 'უთო Tefal FV8066E0 | Kontakt.ge',
            'brand' => ['name' => 'Tefal'],
            'offers' => ['price' => 359.99, 'availability' => 'https://schema.org/InStock'],
            'image' => ['https://kontakt.ge/media/a.jpg'],
        ]);

        Http::fake(fn () => Http::response(
            '<html><head>'.$product.$extraJsonLd.'</head><body>'
            .'<div class="har__row"><div class="har__title">მწარმოებელი</div><div class="har__znach">Tefal</div></div>'
            .'</body></html>'
        ));
    }
}
