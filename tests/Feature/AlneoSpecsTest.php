<?php

namespace Tests\Feature;

use App\Services\Import\Drivers\Alneo\AlneoClient;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Alneo imported nothing to map — no specifications and no category.
 *
 * The client looked for WooCommerce's shop_attributes table and for a theme
 * grid, and alneo.ge has neither: there is no table anywhere on a product
 * page. Their specifications are written into the description, one line each,
 * and their category is printed under the product. So every product arrived
 * bare, the admin's mapping queue stayed empty for this supplier, and there
 * was nothing on screen to say why.
 *
 * The line is the unit, and its container is not: the same shop writes
 * <div>, <li> and <p>, closes a <div> with </p>, and puts a different bullet
 * in front depending on the product. What never varies is "name: value".
 */
class AlneoSpecsTest extends TestCase
{
    /* ------------------------------------------------------------------ reading the lines */

    public function test_a_bulleted_div_list_is_read(): void
    {
        $specs = $this->specs($this->description(
            '<div>• ტიპი: კედლის სპლიტ-სისტემა</div>'
            .'<div>• სიმძლავრე: 9 000 BTU</div>'
        ));

        $this->assertSame(
            [['name' => 'ტიპი', 'value' => 'კედლის სპლიტ-სისტემა'],
                ['name' => 'სიმძლავრე', 'value' => '9 000 BTU']],
            $specs,
        );
    }

    public function test_an_unbulleted_list_is_read(): void
    {
        $specs = $this->specs($this->description(
            '<ul><li>ბრენდი: ALLSCREEN</li><li>მასალა: ფოლადი</li></ul>'
        ));

        $this->assertSame('ბრენდი', $specs[0]['name']);
        $this->assertSame('ფოლადი', $specs[1]['value']);
    }

    /**
     * Their markup does not close what it opens.
     *
     * A <div> that ends in </p> is normal on these pages, and the lines have
     * to survive it — which is why the block is split on closing tags rather
     * than handed to a parser that would repair it first.
     */
    public function test_lines_survive_markup_that_does_not_close_properly(): void
    {
        $specs = $this->specs($this->description(
            '<div>• ტემპერატურა: -7°C ~ +45°C</p>'
            .'<p><strong>ფიზიკური პარამეტრები</strong></div>'
            .'<div>• ძაბვა: 220-240 V</div>'
        ));

        $this->assertCount(2, $specs);
        $this->assertSame('ტემპერატურა', $specs[0]['name']);
        $this->assertSame('220-240 V', $specs[1]['value']);
    }

    /** Only the first colon splits it; a value may hold several. */
    public function test_a_value_may_contain_a_colon(): void
    {
        $specs = $this->specs($this->description('<div>• ძაბვა: Ph-V-Hz: 220-240 V</div>'));

        $this->assertSame('ძაბვა', $specs[0]['name']);
        $this->assertSame('Ph-V-Hz: 220-240 V', $specs[0]['value']);
    }

    /** Whichever bullet this particular product was written with. */
    public function test_every_bullet_is_dropped_from_the_name(): void
    {
        foreach (['•', '-', '–', '—', '·', '*'] as $bullet) {
            $specs = $this->specs($this->description("<div>{$bullet} ფერი: შავი</div>"));

            $this->assertSame('ფერი', $specs[0]['name'] ?? null, $bullet);
        }
    }

    /* ------------------------------------------------------------------ what is not a spec */

    /** A heading names a group of them, and has nothing on its right. */
    public function test_a_heading_is_not_a_spec(): void
    {
        $specs = $this->specs($this->description(
            '<p><strong>მახასიათებლები:</strong></p><div>ფერი: შავი</div>'
        ));

        $this->assertCount(1, $specs);
        $this->assertSame('ფერი', $specs[0]['name']);
    }

    /** Their feature lists are bare statements with nothing to the right. */
    public function test_a_line_without_a_colon_is_not_a_spec(): void
    {
        $specs = $this->specs($this->description(
            '<div>• ღამის რეჟიმი</div><div>• ტაიმერი</div><div>• ფერი: თეთრი</div>'
        ));

        $this->assertCount(1, $specs);
        $this->assertSame('ფერი', $specs[0]['name']);
    }

    /**
     * Prose has colons in it too.
     *
     * A description that runs into a sentence would otherwise become an
     * attribute the length of a paragraph, and the admin would be asked to
     * map it.
     */
    public function test_a_sentence_is_not_a_spec(): void
    {
        $specs = $this->specs($this->description(
            '<p>სანდო გარანტია და მიტანა. შეკვეთა: მხოლოდ alneo.ge-ზე</p>'
        ));

        $this->assertSame([], $specs);
    }

    public function test_a_name_too_long_to_be_one_is_refused(): void
    {
        $specs = $this->specs($this->description(
            '<div>'.str_repeat('ა', 81).': რაღაც</div>'
        ));

        $this->assertSame([], $specs);
    }

    public function test_a_name_the_same_as_an_earlier_one_is_not_repeated(): void
    {
        $specs = $this->specs($this->description(
            '<div>• ფერი: შავი</div><div>• ფერი: თეთრი</div>'
        ));

        $this->assertCount(1, $specs);
        $this->assertSame('შავი', $specs[0]['value']);
    }

    public function test_a_page_with_no_description_block_gives_nothing(): void
    {
        $this->assertSame([], $this->specs('<html><body><h1>პროდუქტი</h1></body></html>'));
    }

    /* ------------------------------------------------------------------ the table, where there is one */

    /**
     * A theme that grows a real attributes table is believed over the text.
     *
     * The table is the shop's own structured answer; the lines are ours,
     * recovered from prose. So the table is read first and a name it has
     * already given is not overwritten by a parsed one.
     */
    public function test_the_attributes_table_is_still_read(): void
    {
        $html = '<table class="shop_attributes">'
            .'<tr><th>ფერი</th><td>ვერცხლისფერი</td></tr>'
            .'</table>'
            .$this->description('<div>• ფერი: შავი</div><div>• ტიპი: გაზის</div>');

        $specs = $this->specs($html);

        $this->assertSame('ვერცხლისფერი', $specs[0]['value']);
        $this->assertSame('ტიპი', $specs[1]['name']);
    }

    /* ------------------------------------------------------------------ the category */

    public function test_the_category_comes_from_the_product_meta(): void
    {
        $this->assertSame('კონდიციონერი', $this->category($this->meta()));
    }

    /**
     * The shop's menu lists every category it has.
     *
     * Each one is a link of exactly the same shape as the product's own, and
     * there are hundreds of them — so a page-wide search answered with
     * whatever sat deepest in the menu. An air conditioner came back as
     * "Android სმარტფონები".
     */
    public function test_the_navigation_menu_is_not_the_product_category(): void
    {
        $menu = '<nav><a href="https://alneo.ge/product-category/1/2/3/4/5/">Android სმარტფონები</a></nav>';

        $this->assertSame('კონდიციონერი', $this->category($menu.$this->meta()));
    }

    /** The brand sits in the same block and is printed the same way. */
    public function test_the_brand_is_not_taken_for_a_category(): void
    {
        $this->assertSame('კონდიციონერი', $this->category($this->meta()));
    }

    /** The deepest of several is the most specific thing they have said. */
    public function test_the_deepest_category_wins(): void
    {
        $html = '<div class="product_meta"><span class="posted_in">კატეგორია: '
            .'<a href="https://alneo.ge/product-category/333/" rel="tag">ტექნიკა</a>, '
            .'<a href="https://alneo.ge/product-category/333/373/374/" rel="tag">კონდიციონერი</a>'
            .'</span></div>';

        $this->assertSame('კონდიციონერი', $this->category($html));
    }

    public function test_a_product_with_no_category_gives_null(): void
    {
        $html = '<div class="product_meta"><span class="sku_wrapper">არტიკული: 01408</span></div>';

        $this->assertNull($this->category($html));
    }

    public function test_a_page_with_no_meta_block_gives_null(): void
    {
        $this->assertNull($this->category('<html><body><h1>პროდუქტი</h1></body></html>'));
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return array<int, array{name:string, value:string}> */
    protected function specs(string $html): array
    {
        return $this->invoke('specs', $html);
    }

    protected function category(string $html): ?string
    {
        return $this->invoke('category', $html);
    }

    protected function invoke(string $method, string $html): mixed
    {
        $client = new AlneoClient([]);

        $reflection = new ReflectionMethod($client, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($client, $html);
    }

    /** The description block, in the wrapper the client looks for. */
    protected function description(string $body): string
    {
        return '<div class="electro-description clearfix">'.$body.'</div>'
            .'<div class="product_meta"></div>';
    }

    /** A product's own meta block, brand and all. */
    protected function meta(): string
    {
        return '<div class="product_meta">'
            .'<span class="sku_wrapper">არტიკული: <span class="sku">01408</span></span>'
            .'<span class="posted_in">კატეგორია: '
            .'<a href="https://alneo.ge/product-category/333/373/374/" rel="tag">კონდიციონერი</a></span>'
            .'<span class="posted_in">Brand: '
            .'<a href="https://alneo.ge/brand/alneo/" rel="tag">ALNEO</a></span>'
            .'</div>';
    }
}
