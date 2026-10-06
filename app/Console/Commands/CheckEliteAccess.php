<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Elite\BlockedByElite;
use App\Services\Import\ImportManager;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Says whether Elite answers this machine, for the API and for the pictures.
 *
 * Elite's API already goes through a worker, so a blocked address shows up only
 * in the gallery — the pictures are fetched straight from their CDN by the
 * shared downloader, which knows nothing about workers. A product can therefore
 * arrive complete and bare, which looks like a bug in the importer and is not.
 *
 * Read-only: it fetches one product and one picture, and writes nothing.
 */
class CheckEliteAccess extends Command
{
    protected $signature = 'import:check-elite {--product= : an id known to exist}';

    protected $description = 'Report whether Elite answers this machine, API and images separately';

    public function handle(ImportManager $manager): int
    {
        $supplier = Supplier::where('code', 'elite')->first();

        if (! $supplier) {
            $this->error('No supplier with code [elite].');

            return self::FAILURE;
        }

        $worker = (string) config('services.elite.worker_url');

        $this->newLine();
        $this->line('Worker:     '.($worker !== '' ? '<fg=green>'.$worker.'</>' : '<fg=red>MISSING</>'));
        $this->line('Token:      '.(config('services.elite.token') ? '<fg=green>set</>' : '<fg=red>MISSING</>'));
        $this->line('Stock file: '.$this->stockRows($supplier).' barcodes loaded');
        $this->newLine();

        [$id, $images] = $this->sampleProduct($manager, $supplier);

        if (! $id) {
            $this->warn('Could not read a product through the worker, so the API route is the '
                .'problem before the pictures are. Fix that first; the message above says how.');

            return self::SUCCESS;
        }

        $this->line("Sample: product <options=bold>{$id}</> with ".count($images).' picture(s)');

        if (! $images) {
            $this->warn('That product carries no pictures at all, so there is nothing to test. '
                .'Try another with --product=<id>.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['route', 'status', 'what came back'], [
            ['CDN, direct', ...$this->describe($this->direct($images[0]))],
            ['CDN, through the worker', ...$this->describe($this->viaWorker($worker, $images[0]))],
        ]);

        $this->verdict($this->direct($images[0]), $this->viaWorker($worker, $images[0]));

        return self::SUCCESS;
    }

    /**
     * A product the stock file lets through, so the test is of the real path.
     *
     * @return array{0: ?string, 1: array<int, string>}
     */
    protected function sampleProduct(ImportManager $manager, Supplier $supplier): array
    {
        $driver = $manager->driver($supplier);

        if ($chosen = $this->option('product')) {
            $payload = $driver->fetch((string) $chosen);

            return [$chosen, $payload?->images ?? []];
        }

        /*
         * Walked rather than guessed: an id only yields a payload when its
         * barcode is in the latest spreadsheet, and most ids are not.
         */
        foreach (range(1, 400) as $id) {
            try {
                $payload = $driver->fetch((string) $id);
            } catch (BlockedByElite $e) {
                $this->error(Redact::secrets($e->getMessage()));

                return [null, []];
            } catch (\Throwable $e) {
                // a slow id is not a blocked one; try the next
                continue;
            }

            if ($payload && $payload->images) {
                return [(string) $id, $payload->images];
            }
        }

        return [null, []];
    }

    /** @return array{status: int|string, body: string, type: string} */
    protected function direct(string $url): array
    {
        return $this->request($url, [
            'User-Agent' => config('services.import.user_agent', 'Mozilla/5.0'),
            'Referer' => parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/',
        ]);
    }

    /** @return array{status: int|string, body: string, type: string} */
    protected function viaWorker(string $worker, string $url): array
    {
        if ($worker === '') {
            return ['status' => 'no worker', 'body' => '', 'type' => ''];
        }

        return $this->request(rtrim($worker, '/').'?'.http_build_query(array_filter([
            'type' => 'image',
            'url' => $url,
            'token' => config('services.elite.token'),
        ])), []);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int|string, body: string, type: string}
     */
    protected function request(string $url, array $headers): array
    {
        try {
            $response = Http::timeout(25)->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders($headers)
                ->get($url);

            return [
                'status' => $response->status(),
                'body' => $response->body(),
                'type' => (string) $response->header('content-type'),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'no answer', 'body' => $e->getMessage(), 'type' => ''];
        }
    }

    /**
     * @param  array{status: int|string, body: string, type: string}  $result
     * @return array{0: string, 1: string}
     */
    protected function describe(array $result): array
    {
        $isImage = str_starts_with($result['type'], 'image/');

        $said = match (true) {
            $isImage => '<fg=green>'.$result['type'].', '.number_format(strlen($result['body'])).' bytes</>',
            $result['status'] === 'no worker' => 'ELITE_WORKER_URL is not set',
            $result['status'] === 'no answer' => Redact::secrets(mb_substr($result['body'], 0, 120)),
            default => Redact::secrets(mb_substr(trim(strip_tags($result['body'])), 0, 55)) ?: '(empty)',
        };

        return [$isImage ? '<fg=green>'.$result['status'].'</>' : '<fg=red>'.$result['status'].'</>', $said];
    }

    /**
     * @param  array{status: int|string, body: string, type: string}  $direct
     * @param  array{status: int|string, body: string, type: string}  $viaWorker
     */
    protected function verdict(array $direct, array $viaWorker): void
    {
        $ok = fn (array $r) => str_starts_with($r['type'], 'image/');

        $this->newLine();

        if ($ok($direct)) {
            $this->info('The CDN answers this machine directly, so the pictures need no worker. '
                .'A gallery that comes out short here is the CDN timing out on a picture or two, '
                .'and the next run fills in whatever is missing.');

            return;
        }

        if ($ok($viaWorker)) {
            $this->warn('The CDN refuses this address but the worker reaches it. The pictures have '
                .'to go the same way the API does — say so and I will route them through it.');

            return;
        }

        $this->warn('Neither route reaches the pictures. Check ELITE_TOKEN and the worker, since '
            .'the same token guards its image branch.');
    }

    protected function stockRows(Supplier $supplier): int
    {
        return SupplierStock::where('supplier_id', $supplier->id)->count();
    }
}
