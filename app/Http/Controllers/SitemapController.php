<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * The sitemap, built from what is actually for sale.
 *
 * Generated rather than stored: a shop whose catalogue changes nightly would
 * otherwise serve a file describing yesterday's.
 *
 * Every page appears once per language, and each entry carries the full set of
 * xhtml:link alternates — that is the form Google asks for, and it is the only
 * way the Georgian and English pages are understood as one page rather than as
 * two that duplicate each other. Slugs are per-language, so each URL is built
 * from that language's own translation rather than by prefixing one slug.
 */
class SitemapController extends Controller
{
    /** Long enough that a crawl never rebuilds it twice, short enough to stay true. */
    protected const CACHE_HOURS = 6;

    public function index()
    {
        $xml = Cache::remember('sitemap', now()->addHours(self::CACHE_HOURS), fn () => $this->build());

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    protected function build(): string
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $writer->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $writer->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        $this->writeStaticPages($writer);
        $this->writeCategories($writer);
        $this->writeProducts($writer);

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /* ------------------------------------------------------------------ sections */

    protected function writeStaticPages(\XMLWriter $writer): void
    {
        $pages = [
            [route('home'), '1.0', 'daily'],
            [route('catalog'), '0.9', 'daily'],
            [route('about'), '0.5', 'monthly'],
            [route('contact'), '0.5', 'monthly'],
        ];

        foreach (array_keys(Store::docs()) as $doc) {
            $pages[] = [route('info', $doc), '0.4', 'monthly'];
        }

        foreach ($pages as [$url, $priority, $frequency]) {
            $this->writeEntry($writer, $this->localeUrls($url), $priority, $frequency);
        }
    }

    protected function writeCategories(\XMLWriter $writer): void
    {
        Category::active()
            ->withTranslation()
            ->with('translations')
            ->chunk(200, function ($categories) use ($writer) {
                foreach ($categories as $category) {
                    $urls = $this->translatedUrls($category, 'catalog');

                    if ($urls) {
                        $this->writeEntry($writer, $urls, '0.8', 'daily', $category->updated_at?->toAtomString());
                    }
                }
            });
    }

    /**
     * Products are streamed in chunks: the whole catalogue in memory is how a
     * sitemap route starts timing out at twenty thousand products.
     */
    protected function writeProducts(\XMLWriter $writer): void
    {
        Product::active()
            ->withTranslation()
            ->with(['translations', 'images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->orderBy('id')
            ->chunk(500, function ($products) use ($writer) {
                foreach ($products as $product) {
                    $urls = $this->translatedUrls($product, 'product');

                    if (! $urls) {
                        continue;
                    }

                    $this->writeEntry(
                        $writer,
                        $urls,
                        '0.7',
                        'weekly',
                        $product->updated_at?->toAtomString(),
                        $product->images->first()?->url(),
                    );
                }
            });
    }

    /* ------------------------------------------------------------------ urls */

    /**
     * The same page in every active language.
     *
     * @return array<string, string>
     */
    protected function localeUrls(string $url): array
    {
        $out = [];

        foreach (array_keys(LaravelLocalization::getSupportedLocales()) as $locale) {
            $out[$locale] = LaravelLocalization::getLocalizedURL($locale, $url, [], false);
        }

        return $out;
    }

    /**
     * A translated model's URL in every language it actually has a slug for.
     *
     * A product translated into Georgian only must not appear under /en with a
     * Georgian slug: that URL redirects, and a sitemap full of redirects is a
     * sitemap Search Console reports as errors.
     *
     * @return array<string, string>
     */
    protected function translatedUrls(Model $model, string $route): array
    {
        $out = [];

        foreach (array_keys(LaravelLocalization::getSupportedLocales()) as $locale) {
            $slug = $model->translate($locale, false)?->slug;

            if (! $slug) {
                continue;
            }

            $out[$locale] = LaravelLocalization::getLocalizedURL($locale, route($route, $slug), [], false);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * One <url> per language, each listing every language as an alternate.
     *
     * @param  array<string, string>  $urls  locale => URL
     */
    protected function writeEntry(
        \XMLWriter $writer,
        array $urls,
        string $priority,
        string $frequency,
        ?string $modified = null,
        ?string $image = null,
    ): void {
        $default = Language::defaultCode();

        foreach ($urls as $locale => $url) {
            $writer->startElement('url');
            $writer->writeElement('loc', $url);

            if ($modified) {
                $writer->writeElement('lastmod', $modified);
            }

            $writer->writeElement('changefreq', $frequency);
            $writer->writeElement('priority', $priority);

            foreach ($urls as $altLocale => $altUrl) {
                $this->writeAlternate($writer, $altLocale, $altUrl);
            }

            // an unmatched language gets the shop's own
            if (isset($urls[$default])) {
                $this->writeAlternate($writer, 'x-default', $urls[$default]);
            }

            if ($image) {
                $writer->startElement('image:image');
                $writer->writeElement('image:loc', $image);
                $writer->endElement();
            }

            $writer->endElement();
        }
    }

    protected function writeAlternate(\XMLWriter $writer, string $locale, string $url): void
    {
        $writer->startElement('xhtml:link');
        $writer->writeAttribute('rel', 'alternate');
        $writer->writeAttribute('hreflang', $locale);
        $writer->writeAttribute('href', $url);
        $writer->endElement();
    }
}
