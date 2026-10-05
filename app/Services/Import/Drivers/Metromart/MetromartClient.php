<?php

namespace App\Services\Import\Drivers\Metromart;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * metromart.ge, an Odoo shop.
 *
 * Its search is a JSON-RPC call that will only answer a request carrying a
 * CSRF token and the session cookie that token was minted with — so the two
 * are fetched together and kept for the run rather than per product.
 */
class MetromartClient
{
    protected const BASE = 'https://metromart.ge';

    protected ?CookieJar $jar = null;
    protected ?string $token = null;

    public function __construct(protected array $config = [])
    {
    }

    /** The product page for a model code, or null when the shop has none. */
    public function findUrl(string $model): ?string
    {
        if (! $this->session()) {
            return null;
        }

        $response = Http::withOptions([
            'cookies'          => $this->jar,
            'force_ip_resolve' => 'v4',
        ])
            ->timeout($this->config['timeout'] ?? 30)
            ->withHeaders([
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With' => 'XMLHttpRequest',
                'User-Agent'       => $this->agent(),
                'Referer'          => self::BASE.'/ka_GE/',
                'Origin'           => self::BASE,
            ])
            ->post(self::BASE.'/find-products-suggestions', [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'params'  => [
                    'search'     => $this->normalise($model),
                    'csrf_token' => $this->token,
                ],
                'id' => random_int(100000000, 999999999),
            ]);

        if (! $response->successful()) {
            Log::channel('import')->warning('metromart search refused', [
                'model'  => $model,
                'status' => $response->status(),
            ]);

            return null;
        }

        $id = $response->json('result.index.0.id');

        return $id ? self::BASE.'/ka_GE/shop/product/'.$id : null;
    }

    /**
     * Everything the product page carries.
     *
     * @return array{name:?string, brand:?string, price:float, sale:float, in_stock:bool, images:array, specs:array}|null
     */
    public function page(string $url): ?array
    {
        $html = $this->get($url);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpath($html);

        $name = $this->text($xpath, '//h1[@itemprop="name"]')
            ?: $this->meta($xpath, 'og:title');

        if (! $name) {
            return null;
        }

        return [
            'name'     => $name,
            'brand'    => $this->attr($xpath, '//meta[@itemprop="brand"]', 'content'),
            'price'    => (float) ($this->meta($xpath, 'product:price:amount') ?? 0),
            'sale'     => (float) ($this->meta($xpath, 'product:sale_price:amount') ?? 0),
            'in_stock' => $this->availableToday($xpath),
            'images'   => $this->images($xpath, $url),
            'specs'    => $this->specs($xpath),
        ];
    }

    /* ------------------------------------------------------------------ parts */

    /**
     * Whether the shop will hand it over in Tbilisi today.
     *
     * A product listed but held in another city is one we cannot promise, so
     * only the "today" button counts as stock.
     */
    protected function availableToday(DOMXPath $xpath): bool
    {
        $nodes = $xpath->query('//*[contains(@class,"js-availability-button-buy") and contains(@class,"get_today")]');

        return $nodes !== false && $nodes->length > 0;
    }

    /**
     * The full specification table, flattened.
     *
     * Odoo groups rows under headings spanning both columns; the group is kept
     * in the name so "Display / Diagonal" does not collide with "Body / Depth".
     *
     * @return array<int, array{name:string, value:string}>
     */
    protected function specs(DOMXPath $xpath): array
    {
        $specs = [];
        $group = null;

        foreach ($xpath->query('//section[@id="product_full_spec"]//table//tr') ?: [] as $row) {
            $heading = $xpath->query('.//th[@colspan="2"]', $row);

            if ($heading && $heading->length > 0) {
                $group = $this->clean($heading->item(0)->textContent);

                continue;
            }

            $cells = $xpath->query('.//td', $row);

            if (! $cells || $cells->length < 2) {
                continue;
            }

            $name = $this->clean($cells->item(0)->textContent);
            $value = $this->clean($cells->item(1)->textContent);

            if ($name === '' || $value === '' || $value === '-') {
                continue;
            }

            $key = $group ? "{$group} — {$name}" : $name;

            $specs[$key] = ['name' => $key, 'value' => $value];
        }

        return array_values($specs);
    }

    /** @return array<int, string> */
    protected function images(DOMXPath $xpath, string $url): array
    {
        $images = [];

        // Odoo serves the main picture from the template's own endpoint
        if (preg_match('/-(\d+)$/', basename(parse_url($url, PHP_URL_PATH)), $m)) {
            $images[] = self::BASE."/web/image/product.template/{$m[1]}/image";
        }

        foreach ($xpath->query('//div[@id="o-carousel-product"]//div[contains(@class,"item")]//img') ?: [] as $img) {
            $src = $img->getAttribute('data-zoom-image') ?: $img->getAttribute('src');

            if (! $src) {
                continue;
            }

            if (str_starts_with($src, '/')) {
                $src = self::BASE.$src;
            }

            // a trailing /640x480 asks for a thumbnail; without it the original comes
            $images[] = preg_replace('#/\d+x\d+$#', '', $src);
        }

        return array_values(array_unique(array_filter($images)));
    }

    /* ------------------------------------------------------------------ session */

    /** The CSRF token and the cookie it belongs to, fetched once per run. */
    protected function session(): bool
    {
        if ($this->token !== null) {
            return true;
        }

        $this->jar = new CookieJar;

        $response = Http::withOptions([
            'cookies'          => $this->jar,
            'force_ip_resolve' => 'v4',
        ])
            ->timeout(20)
            ->retry(2, 1500, throw: false)
            ->withHeaders(['User-Agent' => $this->agent()])
            ->get(self::BASE.'/ka_GE/');

        if (! $response->successful() || ! preg_match('/csrf_token:\s*"([^"]+)"/', $response->body(), $m)) {
            Log::channel('import')->error('metromart session failed', [
                'status' => $response->status(),
            ]);

            return false;
        }

        $this->token = $m[1];

        return true;
    }

    /* ------------------------------------------------------------------ http */

    protected function get(string $url): ?string
    {
        $response = Http::withOptions(['force_ip_resolve' => 'v4'])
            ->timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(15)
            ->retry(2, 1500, throw: false)
            ->withHeaders([
                'User-Agent'      => $this->agent(),
                'Accept'          => 'text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language' => 'ka,en;q=0.9',
                'Referer'         => self::BASE.'/',
            ])
            ->get($url);

        return $response->successful() ? $response->body() : null;
    }

    protected function agent(): string
    {
        return config('services.metromart.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    }

    /** Invisible characters travel in spreadsheets and break an exact search. */
    protected function normalise(string $model): string
    {
        $model = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', ' ', $model);

        return trim(preg_replace('/\s+/u', ' ', $model));
    }

    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function text(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node ? ($this->clean($node->textContent) ?: null) : null;
    }

    protected function attr(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node ? ($this->clean($node->getAttribute($attribute)) ?: null) : null;
    }

    protected function meta(DOMXPath $xpath, string $property): ?string
    {
        foreach (["//meta[@property=\"{$property}\"]/@content", "//meta[@name=\"{$property}\"]/@content"] as $query) {
            $node = $xpath->query($query)?->item(0);

            if ($node) {
                return $this->clean($node->nodeValue);
            }
        }

        return null;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
