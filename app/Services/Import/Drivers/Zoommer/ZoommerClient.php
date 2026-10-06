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

            /*
             * The same cookie under a name no host strips.
             *
             * Shared hosting and some proxies drop or rewrite an inbound Cookie
             * header, and this one carries the only credential the API checks —
             * losing it turns a working proxy into a 401. Sent only when there
             * is a proxy to send it to, because an unknown header on a direct
             * request is one more thing for bot scoring to notice.
             */
            'X-Proxy-Cookie' => config('services.zoommer.worker_url') ? $this->cookie() : null,
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
     * The address to ask, direct or through the shop's own worker.
     *
     * Cloudflare answers this server with 403 whatever cookie it carries —
     * confirmed with plain curl from that machine, so it is the address being
     * refused and no header fixes it. A worker asks from an address that is not
     * refused, which is how the Elite and Alta drivers already reach their
     * sources.
     *
     * The worker's own shape: ?type=product&productId=N, with the access token
     * passed along so it lives in this project's .env and not in two places.
     * Unset, the request goes direct, so a laptop needs no worker at all.
     */
    protected function endpoint(string $id): string
    {
        if (! $worker = config('services.zoommer.worker_url')) {
            return $this->apiUrl."v1/Products/details?productId={$id}";
        }

        return rtrim($worker, '/').'?'.http_build_query(array_filter([
            'type' => 'product',
            'productId' => $id,
            'accessToken' => config('services.zoommer.access_token'),
        ]));
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
                $this->endpoint($id),
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

            /*
             * Which of these you are looking at decides what to go and do, so
             * the message has to know whether a worker was even used. Told
             * flatly to "use a proxy", somebody already using one has nothing
             * to act on.
             */
            $status === 403 || $this->isChallenge($body) => config('services.zoommer.worker_url')
                ? 'Cloudflare refused the worker as well, so the shop is not the problem: the '
                    .'worker fetched zoommer.ge and was refused in its turn. Check it on its own '
                    .'with the curl in the comment above, then its headers — a fetch with no '
                    .'User-Agent or cookie is scored as a bot and gets exactly this.'
                : 'Cloudflare refused the caller, not the request: the token is not what is being '
                    .'rejected, and no header fixes it from here. Set ZOOMMER_WORKER_URL to a '
                    .'worker that fetches zoommer.ge for you, the way the Elite and Alta drivers '
                    .'already do.',

            $status === 429 => 'Too many requests. Lower IMPORT_RATE_PER_MINUTE or run fewer workers.',

            default => 'Zoommer is refusing requests for now; this is usually temporary.',
        };

        $route = config('services.zoommer.worker_url') ? 'through the worker' : 'directly';

        return "Zoommer refused the request (HTTP {$status}, asked {$route}). {$reason}";
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
                yield $id => new Request('GET', $this->endpoint((string) $id), [
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
