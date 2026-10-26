<?php

namespace App\Services\Import\Drivers\Alta;

use App\Support\Redact;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $response = $this->get('/api/proxy/v1/Products/search/suggestions', ['query' => $item]);

        if (! $response) {
            return null;
        }

        $products = $response->json('products');

        if (! is_array($products) || ! $products) {
            /* The code is in the price list but not on the shop front: Alta
               sells it in the branches and does not list it online. */
            return null;
        }

        return $this->take($products[0], $item);
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

            'category_id' => (int) ($product['primaryCategoryId'] ?? 0) ?: null,
            'category' => trim((string) ($product['primaryCategoryName'] ?? '')) ?: null,
        ];
    }

    protected function image(mixed $url): ?string
    {
        $url = trim((string) $url);

        return str_starts_with($url, 'http') ? $url : null;
    }

    protected function get(string $path, array $query = []): ?Response
    {
        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 25))
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => (string) ($this->config['user_agent'] ?? 'iapi.ge importer'),
                ])
                ->get(self::BASE.$path, $query);
        } catch (\Throwable $e) {
            Log::channel('import')->warning('alta.ge could not be reached', [
                'path' => $path,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        Log::channel('import')->warning('alta.ge refused the request', [
            'path' => $path,
            'query' => $query,
            'status' => $response->status(),
            'hint' => $response->status() === 403
                ? 'the shop front is behind a bot check; it answers only callers it accepts'
                : null,
        ]);

        return null;
    }
}
