<?php

namespace App\Support;

use App\Models\Language;
use App\Models\Product;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class Seo
{
    public const IMAGE_WIDTH = 1200;

    public const IMAGE_HEIGHT = 630;

    public const PRICE_VALID_DAYS = 30;

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

        if ($social = array_values(array_filter((array) config('shop.social', [])))) {
            $node['sameAs'] = $social;
        }

        return $node;
    }

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

    public static function breadcrumbs(array $crumbs): ?array
    {
        $items = array_values(array_filter($crumbs, fn ($c) => filled($c['name'] ?? null)));

        if (! $items) {
            return null;
        }

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

    public static function availability(Product $model): string
    {
        if ($model->is_preorder) {
            return 'https://schema.org/PreOrder';
        }

        return (int) $model->stock > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/BackOrder';
    }

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
