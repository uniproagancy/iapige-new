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
    /** Statuses that mean "not you", never "not found". */
    protected const BLOCKED_STATUSES = [401, 403, 429, 503];

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
     *
     * @throws BlockedByZoommer when the source is refusing us rather than
     *                          telling us a product does not exist
     */
    public function fetch(string $id, array $locales = ['ka', 'en']): array
    {
        $out = [];

        foreach ($locales as $locale) {
            $response = $this->client->get(
                $this->apiUrl."v1/Products/details?productId={$id}",
                ['headers' => ['Accept-Language' => $locale]],
            );

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            /*
             * A missing product and a closed door used to look identical here —
             * both were skipped, quietly. So an expired cf_clearance turned a
             * run of a thousand ids into a thousand silent nothings: no product
             * saved, no error, no log line, nothing to tell anybody the cookie
             * needed refreshing. The two cases are told apart now, and only one
             * of them is normal.
             */
            if (in_array($status, self::BLOCKED_STATUSES, true) || $this->isChallenge($body)) {
                throw new BlockedByZoommer($this->refusal($status, $body));
            }

            if ($status !== 200) {
                continue;
            }

            $data = json_decode($body, true);

            if (! empty($data['product'])) {
                $out[$locale] = $data;
            }
        }

        return $out;
    }

    /**
     * What to actually go and fix, per status.
     *
     * These two mean different things and have different answers, and one
     * message covering both sent somebody to rotate a cookie that was not the
     * problem: 401 is the API saying the token is wrong, while 403 is
     * Cloudflare refusing the caller before the API sees it at all — which no
     * cookie fixes when the caller is a datacentre address.
     */
    protected function refusal(int $status, string $body): string
    {
        $reason = match (true) {
            $status === 401 => 'ZOOMMER_ACCESS_TOKEN is missing or no longer valid — that cookie is '
                .'the only one this API checks. Take a fresh zoommer-access_token from a browser '
                .'session on zoommer.ge.',

            $status === 403 || $this->isChallenge($body) => 'Cloudflare refused the caller, not the '
                .'request: the token is not what is being rejected. This is normal from a server '
                .'address, and the way round it is to fetch through a proxy the way the Elite '
                .'driver does, rather than directly.',

            $status === 429 => 'Too many requests. Lower IMPORT_RATE_PER_MINUTE or run fewer workers.',

            default => 'Zoommer is refusing requests for now; this is usually temporary.',
        };

        return "Zoommer refused the request (HTTP {$status}). {$reason}";
    }

    /** Cloudflare answers a challenge with HTML, sometimes under a 200. */
    protected function isChallenge(string $body): bool
    {
        return $body !== ''
            && ! str_starts_with(ltrim($body), '{')
            && (bool) preg_match('/cf-browser-verification|Just a moment|cf_chl|Attention Required/i', $body);
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
