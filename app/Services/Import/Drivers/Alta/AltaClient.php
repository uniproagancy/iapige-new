<?php

namespace App\Services\Import\Drivers\Alta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/*
 | The shop front, asked by item code.
 |
 | The B2B price list gives a code and little else; the site knows that same
 | code as a product's barCode, so one search turns it into the product the
 | shop shows -- its own id, its address, its photograph and the price with
 | the discount on it.
 |
 | alta.ge sits behind a bot check that answers some callers with 403 and a
 | "Just a moment" page instead of the data. That is reported plainly and the
 | caller is left to decide; nothing here tries to look like something it is
 | not.
 */
class AltaClient
{
    protected const BASE = 'https://alta.ge';

    protected const MAX_CANDIDATES = 5;

    /** @var array<int, array<string, mixed>|null> */
    protected array $detailsCache = [];

    public function __construct(protected array $config = []) {}

    /*
     | The address that holds everything about a product: its pictures, its
     | specifications and the price with the discount on it. It is built from
     | the shop front's own id, which the lookup above is what gives us.
     */
    public static function detailsUrl(int $id): string
    {
        return self::BASE.'/api/proxy/v1/Products/details?productId='.$id;
    }

    /**
     * What the shop front knows about one B2B item code.
     *
     * @return array<string, mixed>|null
     */
    public function suggest(string $item): ?array
    {
        $data = $this->product($item);

        return $data ? $this->take($data['product'], trim($item)) : null;
    }

    /**
     * Search results are candidates; only a matching details barcode identifies
     * the supplier's item. A similarly named first result is not enough.
     *
     * @return array<string, mixed>|null
     */
    public function product(string $item): ?array
    {
        $item = trim($item);

        if ($item === '') {
            return null;
        }

        $directory = trim((string) ($this->config['retail_directory'] ?? ''));

        if ($directory !== '') {
            return $this->localProduct($item, $directory);
        }

        $response = $this->get('/api/proxy/v1/Products/search/suggestions', ['query' => $item]);

        if (! $response) {
            return null;
        }

        $document = json_decode($response->body());
        $products = $response->json('products');

        if (! is_object($document) || ! is_array($document->products ?? null)
            || ! is_array($products) || ! array_is_list($products)) {
            throw $this->unavailable('returned an invalid search response', '/api/proxy/v1/Products/search/suggestions');
        }

        $exact = $unidentified = [];

        foreach ($products as $candidate) {
            if (! is_array($candidate)) {
                throw $this->unavailable('returned an invalid search candidate', '/api/proxy/v1/Products/search/suggestions');
            }

            $barcode = $this->barcode($candidate);

            if ($barcode !== '' && $barcode !== $item) {
                continue;
            }

            $id = $this->productId($candidate['id'] ?? null);

            if ($id === null) {
                throw $this->unavailable('returned a search candidate without a valid product ID', '/api/proxy/v1/Products/search/suggestions');
            }

            if ($barcode === $item) {
                $exact[$id] = $id;
            } else {
                $unidentified[$id] = $id;
            }
        }

        foreach (array_slice(array_values($exact + $unidentified), 0, self::MAX_CANDIDATES) as $id) {
            $data = $this->details($id);

            if (! $data || $this->barcode($data['product']) !== $item) {
                continue;
            }

            $this->validateRetailPrice($data['product'], '/api/proxy/v1/Products/details');

            return $data;
        }

        return null;
    }

    /**
     * An explicitly configured export is a source of its own. Missing or stale
     * exports must never quietly change the source to a blocked network API.
     *
     * @return array<string, mixed>|null
     */
    protected function localProduct(string $item, string $directory): ?array
    {
        $source = 'retail-directory';

        if (! preg_match('/\A[A-Za-z0-9_-]+\z/D', $item)) {
            throw $this->unavailable('cannot read an invalid export item code', $source);
        }

        $file = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$item.'.json';

        if (! is_file($file)) {
            return null;
        }

        $maximumAge = max(1, (int) ($this->config['retail_max_age'] ?? 86400));
        $modified = @filemtime($file);

        if ($modified === false || time() - $modified > $maximumAge || $modified > time() + 300) {
            throw $this->unavailable('has an expired retail export', $source);
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw $this->unavailable('could not read the retail export', $source);
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unavailable('has an invalid JSON retail export', $source);
        }

        if (! is_array($data) || ! is_array($data['product'] ?? null)
            || ($id = $this->productId($data['product']['id'] ?? null)) === null
            || $this->barcode($data['product']) !== $item) {
            throw $this->unavailable('has an invalid or mismatched retail export', $source);
        }

        if (array_key_exists('collected_at', $data)) {
            $collected = is_string($data['collected_at']) ? strtotime($data['collected_at']) : false;

            if ($collected === false || time() - $collected > $maximumAge || $collected > time() + 300) {
                throw $this->unavailable('has an expired or invalid retail collection time', $source);
            }
        }

        if (array_key_exists('source_url', $data)) {
            $url = is_string($data['source_url']) ? parse_url($data['source_url']) : false;

            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
                || strtolower((string) ($url['host'] ?? '')) !== 'alta.ge'
                || isset($url['user']) || isset($url['pass'])
                || (isset($url['port']) && $url['port'] !== 443)
                || ! str_ends_with(rtrim((string) ($url['path'] ?? ''), '/'), '-p'.$id)) {
                throw $this->unavailable('has an invalid retail export source URL', $source);
            }
        }

        $this->validateRetailPrice($data['product'], $source);

        return $this->detailsCache[$id] = $data;
    }

    protected function validateRetailPrice(array $product, string $source): void
    {
        $price = $product['price'] ?? null;

        if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0) {
            throw $this->unavailable('returned an invalid current retail price', $source);
        }
    }

    /**
     * Everything the shop front holds about one of its own products.
     *
     * @return array<string, mixed>|null
     */
    public function details(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->detailsCache)) {
            return $this->detailsCache[$id];
        }

        if (trim((string) ($this->config['retail_directory'] ?? '')) !== '') {
            return null;
        }

        $response = $this->get('/api/proxy/v1/Products/details', ['productId' => $id]);

        if (! $response) {
            return $this->detailsCache[$id] = null;
        }

        $data = $response->json();

        if (array_key_exists('product', $data) && $data['product'] === null) {
            return $this->detailsCache[$id] = null;
        }

        if (! is_array($data['product'] ?? null)
            || $this->productId($data['product']['id'] ?? null) !== $id) {
            throw $this->unavailable('returned an invalid product details response', '/api/proxy/v1/Products/details');
        }

        return $this->detailsCache[$id] = $data;
    }

    protected function productId(mixed $id): ?int
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit(trim($id)))) {
            return null;
        }

        return (int) $id > 0 ? (int) $id : null;
    }

    protected function barcode(array $product): string
    {
        $barcode = $product['barCode'] ?? '';

        if (! is_string($barcode) && ! is_int($barcode)) {
            throw $this->unavailable('returned an invalid product barcode', '/api/proxy/v1/Products/details');
        }

        return trim((string) $barcode);
    }

    /**
     * @return array<string, mixed>
     */
    protected function take(array $product, string $item): array
    {
        $price = (float) ($product['price'] ?? 0);
        $previous = (float) ($product['previousPrice'] ?? 0);
        $route = trim((string) ($product['route'] ?? ''));

        return [
            'item' => $item,
            'id' => (int) ($product['id'] ?? 0),
            'name' => trim((string) ($product['name'] ?? '')),
            'route' => $route,
            'url' => $route !== '' ? self::BASE.'/'.ltrim($route, '/') : null,

            'image' => $this->image($product['imageUrl'] ?? null),
            'thumb' => $this->image($product['thumbUrl'] ?? null),

            'price' => $price,

            /* Only a price that is really higher is a previous one; the field
               repeats the current price when nothing is off. */
            'old_price' => $previous > $price ? $previous : null,
            'on_sale' => (bool) ($product['onSale'] ?? false),

            'category_id' => (int) ($product['primaryCategoryId'] ?? $product['categoryId'] ?? 0) ?: null,
            'category' => trim((string) ($product['primaryCategoryName'] ?? $product['categoryName'] ?? '')) ?: null,
        ];
    }

    protected function image(mixed $url): ?string
    {
        $url = trim((string) $url);

        return str_starts_with($url, 'http') ? $url : null;
    }

    protected function get(string $path, array $query = []): ?Response
    {
        $token = trim((string) ($this->config['token'] ?? ''));

        try {
            $request = Http::timeout((int) ($this->config['timeout'] ?? 25))
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Language' => 'ka',
                    'User-Agent' => (string) ($this->config['user_agent'] ?? 'iapi.ge importer'),
                ]);

            /* The access token Alta issued for this account, sent the way its
               own shop front sends it. Without it every answer is a refusal. */
            if ($token !== '') {
                $request = $request->withCookies(['alta-access_token' => $token], 'alta.ge');
            }

            $response = $request->get(self::BASE.$path, $query);
        } catch (Throwable $e) {
            throw $this->unavailable('could not be reached', $path, errorType: $e::class);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->unavailable('refused the request', $path, $response->status());
        }

        $body = ltrim($response->body());

        if (str_starts_with($body, '<')
            && preg_match('/Just a moment|cf-browser-verification|cf_chl|Attention Required/i', $body)) {
            throw $this->unavailable('returned a browser challenge', $path, $response->status());
        }

        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unavailable('returned invalid JSON', $path, $response->status());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw $this->unavailable('returned an invalid JSON response', $path, $response->status());
        }

        return $response;
    }

    protected function unavailable(string $reason, string $path, ?int $status = null, ?string $errorType = null): AltaUnavailable
    {
        Log::channel('import')->warning('alta retail source unavailable', array_filter([
            'path' => $path,
            'reason' => $reason,
            'status' => $status,
            'error_type' => $errorType,
        ], fn ($value) => $value !== null));

        return new AltaUnavailable('Alta retail source '.$reason.($status !== null ? ' (HTTP '.$status.')' : '').'.', $status);
    }
}
