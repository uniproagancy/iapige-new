<?php

namespace App\Support;

use App\Models\Language;
use App\Models\Product;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Everything a crawler reads, built in one place.
 *
 * Meta tags and JSON-LD describe the same page, so they are assembled from the
 * same array: a title that differs between the <title> element and og:title is
 * the kind of mismatch nobody notices until a shared link looks wrong.
 *
 * Only facts the database actually holds are published. A rating invented for
 * a demo would be a fake rich result, which is a manual action rather than a
 * ranking — so aggregateRating appears only once real reviews exist.
 */
class Seo
{
    /** Facebook refuses anything smaller, and Twitter crops to roughly this ratio. */
    public const IMAGE_WIDTH = 1200;

    public const IMAGE_HEIGHT = 630;

    /** How long a price is promised for, in days — Merchant Center wants a date. */
    public const PRICE_VALID_DAYS = 30;

    /**
     * The page's meta, with every key the partials rely on always present.
     *
     * A page may pass a `seo` array, or just the `title` / `description` it
     * already passes to the layout; sections win over neither, they are merged
     * by the layout before this is called.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    public static function meta(array $given = []): array
    {
        $title = trim((string) ($given['title'] ?? '')) ?: __('layout.title');
        $description = trim((string) ($given['description'] ?? '')) ?: __('layout.description');

        return [
            'title' => $title,
            'description' => Str::limit(strip_tags($description), 300, ''),
            'image' => $given['image'] ?? asset('img/og-default.png'),
            'image_alt' => $given['image_alt'] ?? $title,
            'type' => $given['type'] ?? 'website',
            'canonical' => $given['canonical'] ?? url()->current(),
            'robots' => $given['robots'] ?? 'index, follow',
            'price' => $given['price'] ?? null,
            'availability' => $given['availability'] ?? null,
            'schema' => array_values(array_filter((array) ($given['schema'] ?? []))),
        ];
    }

    /**
     * hreflang for every active language, plus x-default.
     *
     * x-default is the default language rather than a language picker, because
     * this shop has no neutral landing page to send an unmatched visitor to.
     *
     * @return array<string, string>
     */
    public static function alternates(): array
    {
        $out = [];

        foreach (array_keys(LaravelLocalization::getSupportedLocales()) as $code) {
            $out[$code] = LaravelLocalization::getLocalizedURL($code, null, [], false);
        }

        $default = Language::defaultCode();

        if (isset($out[$default])) {
            $out['x-default'] = $out[$default];
        }

        return $out;
    }

    /* ================================================================== nodes */

    /** The shop itself. Referenced by @id everywhere else instead of repeated. */
    public static function organization(): array
    {
        $company = (array) config('shop.company');

        $node = [
            '@type' => 'OnlineStore',
            '@id' => url('/').'#organization',
            'name' => config('app.name'),
            'url' => url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('img/logo.png'),
            ],
            'image' => asset('img/og-default.png'),
        ];

        if ($phone = config('shop.phone')) {
            $node['telephone'] = $phone;
            $node['contactPoint'] = [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'telephone' => $phone,
                'email' => config('shop.email'),
                'areaServed' => 'GE',
                'availableLanguage' => array_keys(LaravelLocalization::getSupportedLocales()),
            ];
        }

        if ($email = config('shop.email')) {
            $node['email'] = $email;
        }

        if ($address = $company['address'] ?? null) {
            $node['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $address,
                'addressCountry' => 'GE',
            ];
        }

        if ($taxId = $company['tax_id'] ?? null) {
            $node['taxID'] = $taxId;
        }

        // only the profiles that are actually configured; an empty sameAs is noise
        if ($social = array_values(array_filter((array) config('shop.social', [])))) {
            $node['sameAs'] = $social;
        }

        return $node;
    }

    /** The site, with the search box Google may offer under the brand result. */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'url' => url('/'),
            'name' => config('app.name'),
            'inLanguage' => app()->getLocale(),
            'publisher' => ['@id' => url('/').'#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('catalog').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * @param  array<int, array{name: string, url: string}>  $crumbs
     */
    public static function breadcrumbs(array $crumbs): ?array
    {
        $items = array_values(array_filter($crumbs, fn ($c) => filled($c['name'] ?? null)));

        if (! $items) {
            return null;
        }

        // the home page is the first crumb everywhere, so it is added here once
        array_unshift($items, ['name' => __('common.home'), 'url' => route('home')]);

        return [
            '@type' => 'BreadcrumbList',
            '@id' => url()->current().'#breadcrumbs',
            'itemListElement' => array_map(fn ($c, $i) => array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $c['name'],
                'item' => $c['url'] ?? null,
            ]), $items, array_keys($items)),
        ];
    }

    /**
     * One product with its offer.
     *
     * @param  array<string, mixed>  $card  from Catalog::card()
     */
    public static function product(array $card, Product $model): array
    {
        $url = $card['url'] ?? url()->current();

        $node = [
            '@type' => 'Product',
            '@id' => $url.'#product',
            'name' => trim(($card['brand'] ?? '').' '.($card['name'] ?? '')),
            'url' => $url,
            'image' => array_values(array_filter((array) ($card['gallery'] ?? $card['images'] ?? []))),
            'description' => Str::limit(strip_tags((string) ($card['description'] ?? $card['spec'] ?? '')), 500, ''),
            'offers' => self::offer($card, $model, $url),
        ];

        if ($sku = $card['sku'] ?? $card['code'] ?? null) {
            $node['sku'] = (string) $sku;
            $node['mpn'] = (string) $sku;
        }

        if ($brand = $card['brand'] ?? null) {
            $node['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }

        if ($category = $card['cat'] ?? null) {
            $node['category'] = $category;
        }

        /*
         * Reviews are rich-result territory: a number the shop cannot show on
         * the page is one Google treats as fabricated. So this appears only
         * when the product really carries reviews.
         */
        if ((int) $model->reviews_count > 0 && (float) $model->rating > 0) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $model->rating, 1),
                'reviewCount' => (int) $model->reviews_count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    protected static function offer(array $card, Product $model, string $url): array
    {
        return array_filter([
            '@type' => 'Offer',
            'url' => $url,
            'price' => number_format((float) ($card['price'] ?? 0), 2, '.', ''),
            'priceCurrency' => 'GEL',
            'availability' => self::availability($model),
            'itemCondition' => 'https://schema.org/NewCondition',
            'priceValidUntil' => now()->addDays(self::PRICE_VALID_DAYS)->toDateString(),
            'seller' => ['@id' => url('/').'#organization'],
        ]);
    }

    /**
     * What the shop will actually do with an order.
     *
     * The checkout lets stock go negative on purpose — status and pre-order are
     * what block a sale — so an out-of-stock product is a back-order here, not
     * something the customer cannot buy.
     */
    public static function availability(Product $model): string
    {
        if ($model->is_preorder) {
            return 'https://schema.org/PreOrder';
        }

        return (int) $model->stock > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/BackOrder';
    }

    /**
     * A listing, as the order the visitor sees it in.
     *
     * @param  iterable<int, array<string, mixed>>  $cards
     */
    public static function itemList(iterable $cards, ?string $name = null): ?array
    {
        $items = [];

        foreach ($cards as $card) {
            if (empty($card['url'])) {
                continue;
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'url' => $card['url'],
                'name' => trim(($card['brand'] ?? '').' '.($card['name'] ?? '')),
            ];
        }

        if (! $items) {
            return null;
        }

        return array_filter([
            '@type' => 'ItemList',
            '@id' => url()->current().'#itemlist',
            'name' => $name,
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ]);
    }

    /** A page that is about the shop rather than about a product. */
    public static function page(string $type, string $title, ?string $description = null): array
    {
        return array_filter([
            '@type' => $type,
            '@id' => url()->current().'#page',
            'url' => url()->current(),
            'name' => $title,
            'description' => $description,
            'inLanguage' => app()->getLocale(),
            'isPartOf' => ['@id' => url('/').'#website'],
            'publisher' => ['@id' => url('/').'#organization'],
        ]);
    }

    /* ================================================================== output */

    /**
     * The nodes as one @graph.
     *
     * One script with cross-referenced @id beats several standalone blocks:
     * the organisation is then described once and pointed at from everywhere.
     *
     * @param  array<int, array<string, mixed>|null>  $nodes
     */
    public static function graph(array $nodes): string
    {
        $graph = array_values(array_filter($nodes));

        if (! $graph) {
            return '';
        }

        return (string) json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
