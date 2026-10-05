<?php

namespace App\Services\Import\Drivers\Zoommer;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

/**
 * Cloudflare validates the whole request, not just cf_clearance: the
 * User-Agent must match the one the clearance was issued for, and the
 * sec-ch-ua / Referer headers must look like the site's own fetch.
 */
class ZoommerClient
{
    protected Client $client;

    protected string $apiUrl;

    public function __construct(protected array $config = [])
    {
        $this->apiUrl = rtrim((string) config('services.zoommer.api_url'), '/').'/';

        $this->client = new Client([
            'timeout' => $config['timeout'] ?? 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => false,
            'force_ip_resolve' => 'v4',
            'headers' => $this->headers(),
            'curl' => [
                CURLOPT_DNS_CACHE_TIMEOUT => 300,
            ],
        ]);
    }

    protected function headers(): array
    {
        return array_filter([
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Referer' => config('services.zoommer.site'),
            'User-Agent' => config('services.zoommer.user_agent'),
            'os' => 'web',
            'sec-ch-ua' => config('services.zoommer.sec_ch_ua'),
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'sec-fetch-dest' => 'empty',
            'sec-fetch-mode' => 'cors',
            'sec-fetch-site' => 'same-origin',
            'Cookie' => $this->cookie(),
        ]);
    }

    protected function cookie(): string
    {
        $parts = ['zoommer-cookie_agreed=true'];

        if ($token = config('services.zoommer.access_token')) {
            $parts[] = 'zoommer-access_token='.$token;
        }

        if ($clearance = config('services.zoommer.cf_clearance')) {
            $parts[] = 'cf_clearance='.$clearance;
        }

        return implode('; ', $parts);
    }

    /**
     * One product in every language we keep.
     *
     * @return array<string, array> locale => raw payload
     */
    public function fetch(string $id, array $locales = ['ka', 'en']): array
    {
        $out = [];

        foreach ($locales as $locale) {
            $response = $this->client->get(
                $this->apiUrl."v1/Products/details?productId={$id}",
                ['headers' => ['Accept-Language' => $locale]],
            );

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $data = json_decode((string) $response->getBody(), true);

            if (! empty($data['product'])) {
                $out[$locale] = $data;
            }
        }

        return $out;
    }

    /**
     * Many products at once — the pool keeps the source at a pace it tolerates.
     * Used by the driver to find which ids exist before queueing them.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, array> id => raw payload (ka only)
     */
    public function fetchMany(array $ids, int $concurrency = 10): array
    {
        $found = [];

        $requests = function () use ($ids) {
            foreach ($ids as $id) {
                yield $id => new Request('GET', $this->apiUrl."v1/Products/details?productId={$id}", [
                    'Accept-Language' => 'ka',
                ]);
            }
        };

        (new Pool($this->client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $id) use (&$found) {
                if ($response->getStatusCode() !== 200) {
                    return;
                }

                $data = json_decode((string) $response->getBody(), true);

                if (! empty($data['product'])) {
                    $found[$id] = $data;
                }
            },
        ]))->promise()->wait();

        return $found;
    }
}
