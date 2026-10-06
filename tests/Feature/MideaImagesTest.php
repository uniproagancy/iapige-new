<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\Import\Drivers\Midea\MideaClient;
use App\Services\Import\Drivers\Midea\MideaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Midea products arrived without photographs, and only some of them.
 *
 * Two separate causes. The pictures on a listing card were kept while the ones
 * on a product's own page were all thrown away, because the filter that tells a
 * photograph from a logo was looking under /storage/ and this site serves
 * everything from /uploads/products/. And the crawl only walked the seven
 * listing pages named in the config, which indexed 54 cards against 368 models
 * in the price list, so most products were never looked for at all.
 */
class MideaImagesTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'midea', 'name' => 'Midea', 'driver' => MideaDriver::class,
            'is_active' => true, 'priority' => 1, 'markup' => [['percent' => 10]],
            'config' => ['locale' => 'ka', 'read_site' => true, 'listings' => [], 'max_pages' => 1],
        ]);

        Cache::flush();
    }

    /**
     * The filter must match where this site actually keeps its photographs.
     *
     * At /storage/ it rejected every real one, so any product whose pictures
     * came from its own page — which is most of them — came in bare.
     */
    public function test_product_page_photographs_are_kept(): void
    {
        config(['services.midea.image_path' => '/uploads/products/']);

        Http::fake(fn () => Http::response($this->productPage()));

        $page = (new MideaClient)->page('https://www.midea.ge/ka/product/x/1/');

        $this->assertSame([
            'https://www.midea.ge/uploads/products/one.jpg',
            'https://www.midea.ge/uploads/products/two.png',
        ], $page['images']);
    }

    /** A logo is not a product photograph, whatever the filter is set to. */
    public function test_logos_and_banners_are_still_rejected(): void
    {
        config(['services.midea.image_path' => '/uploads/products/']);

        Http::fake(fn () => Http::response($this->productPage()));

        $images = (new MideaClient)->page('https://www.midea.ge/ka/product/x/1/')['images'];

        foreach ($images as $url) {
            $this->assertStringNotContainsString('general-logo', $url);
            $this->assertStringNotContainsString('banner', $url);
        }
    }

    /**
     * The site writes some of its own addresses with a doubled slash, and
     * serves them either way — so one picture had two spellings, and the stored
     * file is named after a hash of the address.
     */
    public function test_a_doubled_slash_is_one_address(): void
    {
        $client = new MideaClient;

        $this->assertSame(
            $client->absolute('https://www.midea.ge/uploads/products/a.jpg'),
            $client->absolute('https://www.midea.ge//uploads/products/a.jpg'),
        );
    }

    public function test_relative_addresses_still_resolve(): void
    {
        $client = new MideaClient;

        $this->assertSame(
            'https://www.midea.ge/uploads/products/a.jpg',
            $client->absolute('/uploads/products/a.jpg'),
        );
    }

    /**
     * A model the listings never reach is found through the sitemap.
     *
     * Whole categories — small appliances, built-in appliances, microwaves —
     * had no listing configured at all, so nothing ever looked for them.
     */
    public function test_a_model_outside_the_listings_is_found_in_the_sitemap(): void
    {
        $this->fakeSitemap();

        $driver = new MideaDriver($this->supplier);
        $card = $this->siteCard($driver, 'MG9005TX');

        $this->assertSame('https://www.midea.ge/ka/product/chasashenebeli-zeda-paneli-midea-mg9005tx/934/', $card['url']);
        $this->assertTrue($card['needs_page'], 'a sitemap match has no card, so its page must be read');
    }

    /** The slug carries the model in lower case, buried in the product name. */
    public function test_matching_survives_the_slug_spelling(): void
    {
        $this->fakeSitemap();

        $driver = new MideaDriver($this->supplier);

        $this->assertNotEmpty($this->siteCard($driver, 'MO-37001-GB'));
        $this->assertNotEmpty($this->siteCard($driver, 'mo37001gb'));
    }

    /** A code too short to be distinctive would match the wrong product. */
    public function test_a_very_short_code_is_not_matched(): void
    {
        $this->fakeSitemap();

        $this->assertSame([], $this->siteCard(new MideaDriver($this->supplier), 'MG'));
    }

    public function test_a_model_on_neither_side_is_not_invented(): void
    {
        $this->fakeSitemap();

        $this->assertSame([], $this->siteCard(new MideaDriver($this->supplier), 'NOTHING-LIKE-THIS'));
    }

    /** With read_site off, nothing is fetched at all. */
    public function test_the_site_is_left_alone_when_it_is_switched_off(): void
    {
        $this->supplier->update(['config' => ['read_site' => false] + $this->supplier->config]);

        Http::fake();

        $this->assertSame([], $this->siteCard(new MideaDriver($this->supplier), 'MG9005TX'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ helpers */

    protected function siteCard(MideaDriver $driver, string $model): array
    {
        $method = new ReflectionMethod($driver, 'siteCard');
        $method->setAccessible(true);

        return $method->invoke($driver, $model);
    }

    protected function fakeSitemap(): void
    {
        Http::fake([
            '*/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex><sitemap>'
                .'<loc>https://www.midea.ge/sitemaps/sitemap_ka.xml</loc></sitemap><sitemap>'
                .'<loc>https://www.midea.ge/sitemaps/sitemap_en.xml</loc></sitemap></sitemapindex>'
            ),
            '*/sitemap_ka.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.midea.ge/ka/product/chasashenebeli-zeda-paneli-midea-mg9005tx/934/</loc></url>'
                .'<url><loc>https://www.midea.ge/ka/product/chasashenebeli-eleqtro-gumeli-mo-37001-gb-/350/</loc></url>'
                .'<url><loc>https://www.midea.ge/ka/about/</loc></url>'
                .'</urlset>'
            ),
            // the other language is the same products again, so it is skipped
            '*/sitemap_en.xml' => Http::response('<?xml version="1.0"?><urlset></urlset>'),
        ]);
    }

    protected function productPage(): string
    {
        return '<html><body>'
            .'<img src="img/general-logo.png">'
            .'<img src="/uploads/banners/sale.jpg">'
            .'<img src="https://www.midea.ge//uploads/products/one.jpg">'
            .'<div style="background-image:url(\'/uploads/products/two.png\')"></div>'
            .'</body></html>';
    }
}
