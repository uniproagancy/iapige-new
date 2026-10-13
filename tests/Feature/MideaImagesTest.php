<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Midea products arrived without photographs, sometimes.
 *
 * Three separate reasons, all of them silent — the spreadsheet was right, the
 * price was right, and only the picture was missing:
 *
 *  - a listing card's only image is a CSS background on its thumbnail, and
 *    when that one parse yielded nothing the product had no fallback, because
 *    the product page was read for sitemap matches alone;
 *  - a model code under five characters was refused a sitemap match outright,
 *    to avoid matching the wrong slug, which left every short-coded product
 *    with nothing;
 *  - an index that came back empty, because the site was down for a minute,
 *    was cached as the answer for six hours, so every product imported in
 *    that window came in bare and the next run looked no different.
 */
class MideaImagesTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ the fallback */

    /**
     * A card whose thumbnail gave nothing asks the product page.
     *
     * This is the "sometimes": the model was found, the price was read, and
     * the picture was quietly absent.
     */
    public function test_a_card_with_no_thumbnail_falls_back_to_the_page(): void
    {
        $this->fake(cardImage: null);
        $this->row('6931474700001', model: 'MO-37001-GB');

        $payload = $this->driver()->fetch('6931474700001');

        $this->assertNotEmpty($payload->images);
        $this->assertContains('https://midea.ge/uploads/products/gallery-1.jpg', $payload->images);
    }

    /** A card that has its thumbnail costs no second request. */
    public function test_a_card_with_a_thumbnail_does_not_read_the_page(): void
    {
        $this->fake(cardImage: 'https://midea.ge/uploads/products/thumb.jpg');
        $this->row('6931474700001', model: 'MO-37001-GB');

        $payload = $this->driver()->fetch('6931474700001');

        $this->assertSame(['https://midea.ge/uploads/products/thumb.jpg'], $payload->images);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/product/'));
    }

    /* ------------------------------------------------------------------ short codes */

    /**
     * A short code is matched at the end of a slug rather than anywhere in it.
     *
     * Refusing it altogether was safe and left the product bare; anchoring is
     * just as safe and finds it.
     */
    public function test_a_short_model_code_is_matched_at_the_end_of_the_slug(): void
    {
        $this->fake(cardImage: null, listingModel: 'OTHER-999', sitemapSlug: 'chasashenebeli-gumeli-mo37');
        $this->row('6931474700002', model: 'MO37');

        $payload = $this->driver()->fetch('6931474700002');

        $this->assertNotEmpty($payload->images);
    }

    /** Anchored, so a short code in the middle of an unrelated slug is not a match. */
    public function test_a_short_code_in_the_middle_of_a_slug_is_not_a_match(): void
    {
        $this->fake(cardImage: null, listingModel: 'OTHER-999', sitemapSlug: 'mo37-sxva-produqti-xyz');
        $this->row('6931474700002', model: 'MO37');

        $this->assertSame([], $this->driver()->fetch('6931474700002')->images);
    }

    /* ------------------------------------------------------------------ the cache */

    /**
     * An empty index is a failed attempt, not an answer.
     *
     * Cached, it made every product for the next six hours arrive bare — and
     * the run after it looked identical, because by then nothing was failing.
     */
    public function test_an_empty_index_is_not_cached(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->row('6931474700003', model: 'MO-37001-GB');

        $this->driver()->fetch('6931474700003');

        $this->assertNull(Cache::get('import:midea:site-index'));
        $this->assertNull(Cache::get('import:midea:sitemap-index'));
    }

    public function test_an_index_that_found_something_is_cached(): void
    {
        $this->fake(cardImage: 'https://midea.ge/uploads/products/thumb.jpg');
        $this->row('6931474700001', model: 'MO-37001-GB');

        $this->driver()->fetch('6931474700001');

        $this->assertNotEmpty(Cache::get('import:midea:site-index'));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->supplier = Supplier::create([
            'code' => 'midea',
            'name' => 'Midea',
            'driver' => MideaDriver::class,
            'is_active' => true,
            'priority' => 40,
            'markup' => [['percent' => 20]],
            'config' => [
                'locale' => 'ka',
                'brand' => 'Midea',
                'read_site' => true,
                'read_product_page' => false,
                'max_pages' => 1,
                'listings' => ['/ka/products/gamwovi/chasashenebeli-gamwovi/all/1/1/'],
                'columns' => [
                    'key' => 'C', 'category' => 'A', 'model' => 'B',
                    'description' => 'D', 'retail' => 'E', 'stock' => 'H',
                ],
                'price_from' => 'retail',
            ],
        ]);
    }

    protected function driver(): MideaDriver
    {
        return new MideaDriver($this->supplier->fresh());
    }

    protected function row(string $barcode, string $model): SupplierStock
    {
        return SupplierStock::create([
            'supplier_id' => $this->supplier->id,
            'external_id' => $barcode,
            'quantity' => 4,
            'cost_price' => 800,
            'data' => [
                'model' => $model,
                'category' => 'გამწოვი',
                'description' => 'ჩასაშენებელი გამწოვი',
                'retail' => '990',
            ],
            'synced_at' => now(),
        ]);
    }

    /**
     * The site, as far as this driver reads it: one listing page, one sitemap,
     * one product page.
     */
    protected function fake(
        ?string $cardImage,
        string $listingModel = 'MO-37001-GB',
        string $sitemapSlug = 'chasashenebeli-eleqtro-gumeli-mo-37001-gb',
    ): void {
        $style = $cardImage ? ' style="background-image: url('.$cardImage.')"' : '';

        $listing = '<html><body>'
            .'<a class="products-item" href="/ka/product/'.$sitemapSlug.'/4821">'
            .'<div class="products-item-image"'.$style.'></div>'
            .'<div class="products-item-title-text">ჩასაშენებელი გამწოვი</div>'
            .'<div class="products-item-model-value">'.$listingModel.'</div>'
            .'<div class="products-item-price-value">990</div>'
            .'</a></body></html>';

        /*
         * Two documents, as the site really serves them: /sitemap.xml lists one
         * sub-sitemap per language, and only the one named for this locale is
         * read. A flat urlset here would make the sitemap path look broken when
         * it is not.
         */
        $sitemapIndex = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>https://midea.ge/sitemap_products_ka.xml</loc></sitemap>'
            .'</sitemapindex>';

        $sitemap = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://midea.ge/ka/product/'.$sitemapSlug.'/4821</loc></url>'
            .'</urlset>';

        $page = '<html><body>'
            .'<img src="https://midea.ge/uploads/products/gallery-1.jpg">'
            .'</body></html>';

        Http::fake(function ($request) use ($listing, $sitemapIndex, $sitemap, $page) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/sitemap.xml') => Http::response($sitemapIndex, 200),
                str_contains($url, 'sitemap_products') => Http::response($sitemap, 200),
                str_contains($url, '/products/') => Http::response($listing, 200),
                default => Http::response($page, 200),
            };
        });
    }
}
