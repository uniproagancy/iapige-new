<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Says why Zoommer is or is not answering this machine.
 *
 * The same refusal has three different causes with three different answers —
 * the token, the calling address, or the worker in front of it — and telling
 * them apart from a stack trace meant reading a message that scrolls off the
 * screen. This asks every route once and prints what came back.
 *
 * Read-only: it fetches one product and writes nothing.
 */
class CheckZoommerAccess extends Command
{
    protected $signature = 'import:check-zoommer {--product=54500 : an id known to exist}';

    protected $description = 'Report whether Zoommer answers this machine, and by which route';

    public function handle(): int
    {
        $id = (string) $this->option('product');
        $token = (string) config('services.zoommer.access_token');
        $worker = (string) config('services.zoommer.worker_url');

        $this->newLine();
        $this->line('Token in .env:  '.($token !== ''
            ? '<fg=green>set</> ('.mb_strlen($token).' chars)'
            : '<fg=red>MISSING</>'));
        $this->line('Worker in .env: '.($worker !== ''
            ? '<fg=green>'.$worker.'</>'
            : '<fg=yellow>not set — requests go direct</>'));
        $this->newLine();

        $rows = [['direct', ...$this->describe($this->direct($id, $token))]];

        if ($worker !== '') {
            $rows[] = ['through the worker', ...$this->describe($this->viaWorker($worker, $id, $token))];
            $rows[] = ['worker, no token', ...$this->describe($this->viaWorker($worker, $id, ''))];
        }

        $this->table(['route', 'status', 'what came back'], $rows);
        $this->verdict($rows, $worker, $token);

        return self::SUCCESS;
    }

    /** @return array{status: int|string, body: string} */
    protected function direct(string $id, string $token): array
    {
        return $this->request('https://zoommer.ge/api/proxy/v1/Products/details?productId='.$id, array_filter([
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'ka',
            'Referer' => 'https://zoommer.ge/',
            'os' => 'web',
            'User-Agent' => config('services.zoommer.user_agent')
                ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Cookie' => 'zoommer-cookie_agreed=true'.($token !== '' ? '; zoommer-access_token='.$token : ''),
        ]));
    }

    /** @return array{status: int|string, body: string} */
    protected function viaWorker(string $worker, string $id, string $token): array
    {
        return $this->request(rtrim($worker, '/').'?'.http_build_query(array_filter([
            'type' => 'product',
            'productId' => $id,
            'accessToken' => $token !== '' ? $token : null,
        ])), ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int|string, body: string}
     */
    protected function request(string $url, array $headers): array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders($headers)
                ->get($url);

            return ['status' => $response->status(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            return ['status' => 'no answer', 'body' => $e->getMessage()];
        }
    }

    /**
     * @param  array{status: int|string, body: string}  $result
     * @return array{0: string, 1: string}
     */
    protected function describe(array $result): array
    {
        $data = json_decode($result['body'], true);

        $said = match (true) {
            ! empty($data['product']['name']) => '<fg=green>'.$data['product']['name'].'</>',
            isset($data['error']) => 'error: '.$data['error'],
            is_array($data) && array_key_exists('product', $data) => 'no such product (which is a working route)',
            $result['status'] === 'no answer' => mb_substr($result['body'], 0, 60),
            (bool) $this->cloudflareCode($result['body']) => $this->cloudflareCode($result['body']),
            default => mb_substr(trim(strip_tags($result['body'])), 0, 60) ?: '(empty)',
        };

        $colour = $result['status'] === 200 ? 'green' : 'red';

        return ["<fg={$colour}>{$result['status']}</>", $said];
    }

    /**
     * Cloudflare's own numbered refusals, spelled out.
     *
     * The body of one of these says only "error code: 1006", which is the
     * difference between an address somebody has banned and a challenge that
     * would pass on a retry — and nobody should have to go and look it up.
     */
    protected function cloudflareCode(string $body): ?string
    {
        if (! preg_match('/error code:\s*(\d{4})/i', $body, $m)) {
            return null;
        }

        $code = $m[1];

        $meaning = match ($code) {
            '1006', '1007', '1008' => 'the site owner has banned this IP address',
            '1009' => 'the site owner has blocked this country',
            '1010' => 'the site refused the browser fingerprint',
            '1015' => 'rate limited by the site owner',
            '1020' => 'blocked by one of the site owner firewall rules',
            default => 'a Cloudflare refusal',
        };

        return "Cloudflare {$code} — {$meaning}";
    }

    /** @param  array<int, array<int, string>>  $rows */
    protected function verdict(array $rows, string $worker, string $token): void
    {
        $status = fn (int $i) => (int) filter_var($rows[$i][1] ?? '', FILTER_SANITIZE_NUMBER_INT);

        $direct = $status(0);
        $viaWorker = isset($rows[1]) ? $status(1) : null;

        $this->newLine();

        if ($direct === 200) {
            $this->info('Zoommer answers this machine directly. No worker needed here — leave '
                .'ZOOMMER_WORKER_URL empty and the import will go straight to the source.');

            return;
        }

        if ($viaWorker === 200) {
            $this->info('The worker gets through. Make sure ZOOMMER_WORKER_URL is set in .env on '
                .'this machine, then: php artisan config:clear');

            return;
        }

        if ($direct === 401) {
            $this->warn('The address is fine and the token is not: ZOOMMER_ACCESS_TOKEN is missing '
                .'or expired. Take a fresh zoommer-access_token from a browser on zoommer.ge.');

            return;
        }

        if ($worker === '') {
            $this->warn('This address is refused (403) whatever it sends — that is Cloudflare '
                .'turning away the caller, and no cookie or header changes it. Set '
                .'ZOOMMER_WORKER_URL to a worker that fetches zoommer.ge for you, then run this '
                .'again: it will test that route too.');

            return;
        }

        $this->warn('Both routes are refused, so the shop is not the problem: the worker itself is '
            .'being turned away by Cloudflare. Check the worker on its own in the Cloudflare '
            .'dashboard; if it is refused there too, the way out is a proxy somewhere that is not '
            .'Cloudflare.');

        if ($token === '') {
            $this->line('  Note: ZOOMMER_ACCESS_TOKEN is empty here, so neither route carried one.');
        }
    }
}
