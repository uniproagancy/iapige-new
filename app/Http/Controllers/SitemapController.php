<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;

/**
 * The sitemap, built from what is actually for sale.
 *
 * Generated rather than stored: a shop whose catalogue changes nightly would
 * otherwise serve a file describing yesterday's.
 */
class SitemapController extends Controller
{
    public function index()
    {
        $xml = Cache::remember('sitemap', now()->addHours(6), fn () => $this->build());

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    protected function build(): string
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $this->url($writer, route('home'), '1.0', 'daily');
        $this->url($writer, route('catalog'), '0.9', 'daily');

        foreach (Category::active()->withTranslation()->get() as $category) {
            $this->url($writer, route('catalog', $category->slug), '0.8', 'daily');
        }

        /*
         * Products are streamed in chunks: the whole catalogue in memory is how
         * a sitemap route starts timing out at twenty thousand products.
         */
        Product::active()
            ->withTranslation()
            ->orderBy('id')
            ->chunk(500, function ($products) use ($writer) {
                foreach ($products as $product) {
                    if (! $product->slug) {
                        continue;
                    }

                    $this->url(
                        $writer,
                        route('product', $product->slug),
                        '0.7',
                        'weekly',
                        $product->updated_at?->toAtomString(),
                    );
                }
            });

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    protected function url(\XMLWriter $writer, string $loc, string $priority, string $frequency, ?string $modified = null): void
    {
        $writer->startElement('url');
        $writer->writeElement('loc', $loc);

        if ($modified) {
            $writer->writeElement('lastmod', $modified);
        }

        $writer->writeElement('changefreq', $frequency);
        $writer->writeElement('priority', $priority);
        $writer->endElement();
    }
}
