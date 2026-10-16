<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportDoctor extends Command
{
    protected $signature = 'import:doctor
                            {supplier : the supplier code}
                            {--sample= : actually try this many random rows against the source}
                            {--ping= : time this many bare requests to the supplier\'s own host}';

    protected $description = 'Report where a supplier’s import is losing products';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        $this->settings($supplier);
        $this->funnel($supplier);
        $this->queue($supplier);
        $this->quality($supplier);

        if ($ping = (int) $this->option('ping')) {
            $this->ping($supplier, $ping);
        }

        if ($sample = (int) $this->option('sample')) {
            $this->sample($supplier, $sample);
        }

        return self::SUCCESS;
    }

    protected function ping(Supplier $supplier, int $times): void
    {
        $url = $this->hostFor($supplier);

        if (! $url) {
            $this->newLine();
            $this->warn('  No address to ping: this supplier has no base_url and no stored links.');

            return;
        }

        $this->newLine();
        $this->line('<options=bold>reaching them</> — '.$url);

        $rows = [];
        $connects = [];

        for ($i = 0; $i < $times; $i++) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0'],
            ]);

            curl_exec($handle);

            $dns = curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME);
            $connect = curl_getinfo($handle, CURLINFO_CONNECT_TIME);
            $total = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
            $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);

            curl_close($handle);

            $connects[] = $connect;

            $rows[] = [
                $i + 1,
                $code ?: '—',
                $this->ms($dns),
                $this->ms($connect),
                $this->ms($total),
                $error ? mb_substr($error, 0, 40) : '',
            ];
        }

        $this->table(['#', 'http', 'dns', 'connect', 'total', 'error'], $rows);

        $worst = max($connects);

        if ($worst > 2) {
            $this->line('  <fg=red>Connecting takes '.$this->ms($worst).'.</> That is the network, not the import:');
            $this->line('  nothing this project can change will make it faster. Check whether the host');
            $this->line('  can reach them at all, and whether they are refusing this server’s address.');
        } else {
            $this->line('  <fg=green>Connecting is quick.</> A slow run is then the supplier thinking, which');
            $this->line('  pacing helps with — see the rate in its config.');
        }
    }

    protected function hostFor(Supplier $supplier): ?string
    {
        if ($base = config('services.'.$supplier->code.'.base_url')) {
            return rtrim((string) $base, '/').'/';
        }

        $stored = SupplierStock::where('supplier_id', $supplier->id)
            ->whereNotNull('data')
            ->latest('id')
            ->take(20)
            ->pluck('data')
            ->map(fn ($data) => is_array($data) ? ($data['url'] ?? null) : null)
            ->first(fn ($url) => is_string($url) && str_starts_with($url, 'http'));

        if (! $stored) {
            return null;
        }

        $parts = parse_url($stored);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/';
    }

    protected function ms(float $seconds): string
    {
        return number_format($seconds * 1000).' ms';
    }

    protected function sample(Supplier $supplier, int $size): void
    {
        $ids = SupplierStock::where('supplier_id', $supplier->id)
            ->where('quantity', '>', 0)
            ->inRandomOrder()
            ->limit($size)
            ->pluck('external_id');

        if ($ids->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>a real sample</> — '.$ids->count().' rows, straight through the driver');

        $driver = app(ImportManager::class)->driver($supplier);

        $found = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();

        foreach ($ids as $id) {
            try {
                $driver->fetch((string) $id) ? $found++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $rate = round($found / $ids->count() * 100);

        $this->table(['outcome', 'count'], [
            ['the source described it', $found],
            ['the source had nothing', $skipped],
            ['it threw', $failed],
        ]);

        $queued = SupplierStock::where('supplier_id', $supplier->id)->where('quantity', '>', 0)->count();

        $this->line("  <options=bold>{$rate}%</> of rows produce a product.");
        $this->line('  Over '.number_format($queued).' rows that is roughly <options=bold>'
            .number_format((int) round($queued * $rate / 100)).'</> products.');
    }

    protected function settings(Supplier $supplier): void
    {
        $config = $supplier->config ?? [];
        $columns = $config['columns'] ?? [];

        $this->newLine();
        $this->line("<options=bold>{$supplier->code}</> — {$supplier->name}");
        $this->line('  last run: '.($supplier->last_run_at ?? 'never'));

        $rows = [
            ['column map', $columns ? json_encode($columns) : '<fg=red>none</>'],
            ['stock column', $columns['stock'] ?? '<fg=red>not mapped — every row counts as 1</>'],
            ['min_price', $config['min_price'] ?? '—'],
            ['min_stock', $config['min_stock'] ?? '—'],
            ['min_cost', $config['min_cost'] ?? '—'],
            ['require_stock', $this->yesNo($config['require_stock'] ?? config('shop.require_stock'))],
            ['require_image', $this->yesNo($config['require_image'] ?? config('shop.require_image'))],
            ['markup', json_encode($supplier->markup)],
        ];

        $this->table(['setting', 'value'], $rows);

        $this->line('  full config: '.json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->warnAboutDrift($supplier, $config);
    }

    protected function warnAboutDrift(Supplier $supplier, array $config): void
    {

        if (empty($config['columns'])) {
            $this->line('  Fed by pasted addresses, not a price list.');

            return;
        }

        $expected = [
            'columns.key' => 'the price list cannot be loaded at all',
            'columns.stock' => 'every row counts as one available',
        ];

        if (str_contains((string) $supplier->driver, 'Midea')) {
            $expected['columns.model'] = 'nothing can be matched against the website';
            $expected['listings'] = 'the website is never crawled';
        }

        foreach ($expected as $key => $consequence) {
            if (data_get($config, $key) === null) {
                $this->line("  <fg=red>missing {$key}</> — {$consequence}");
            }
        }

        if (($config['read_site'] ?? null) === false) {
            $this->line('  <fg=red>read_site is off</> — no product can gain a photograph or a name from the website');
        }
    }

    protected function funnel(Supplier $supplier): void
    {
        $config = $supplier->config ?? [];
        $minStock = (int) ($config['min_stock'] ?? 0);
        $minPrice = (float) ($config['min_price'] ?? 0);

        $stocks = SupplierStock::where('supplier_id', $supplier->id);

        $rows = (clone $stocks)->count();

        if ($rows === 0) {
            $this->newLine();
            $this->warn(empty($supplier->config['columns'])
                ? '  Nothing added yet. Paste the addresses on the links screen first.'
                : '  No price list loaded at all. Run import:file first.');

            return;
        }

        $inStock = (clone $stocks)->where('quantity', '>', 0)->count();
        $clearStock = (clone $stocks)->where('quantity', '>=', max(1, $minStock))->count();

        [$clearPrice, $clearBoth] = $this->countByPrice($supplier, $minPrice, max(1, $minStock), $rows, $clearStock);

        $offers = ProductOffer::where('supplier_id', $supplier->id)->count();
        $live = ProductOffer::where('supplier_id', $supplier->id)
            ->whereHas('product', fn ($q) => $q->where('status', Product::STATUS_ACTIVE))
            ->count();

        $this->newLine();
        $this->line('<options=bold>from the price list to the shop</>');

        $this->table(['step', 'count', 'of rows'], [
            ['rows in the price list', $rows, '100%'],
            ['  with anything in stock', $inStock, $this->share($inStock, $rows)],
            ["  clearing min_stock ({$minStock})", $clearStock, $this->share($clearStock, $rows)],
            ['  clearing min_price ('.($minPrice ?: '—').')', $clearPrice, $this->share($clearPrice, $rows)],
            ['  clearing both', $clearBoth, $this->share($clearBoth, $rows)],
            ['products we hold for them', $offers, $this->share($offers, $rows)],
            ['  of those, on sale now', $live, $this->share($live, $rows)],
        ]);

        if ($offers < $clearBoth) {
            $missing = $clearBoth - $offers;

            $this->line("  <fg=yellow>{$missing} rows clear every rule and have no product.</>");
            $this->line('  Either the run has not finished, or the supplier’s site does not carry them.');
        }
    }

    protected function countByPrice(Supplier $supplier, float $minPrice, int $minStock, int $rows, int $clearStock): array
    {
        if ($minPrice <= 0) {
            return [$rows, $clearStock];
        }

        $clearPrice = 0;
        $clearBoth = 0;

        SupplierStock::where('supplier_id', $supplier->id)
            ->select(['id', 'quantity', 'cost_price', 'data'])
            ->lazyById(1000)
            ->each(function (SupplierStock $row) use ($minPrice, $minStock, &$clearPrice, &$clearBoth) {
                $paid = (float) ($row->data['sale'] ?? 0) ?: (float) $row->cost_price;

                if ($paid < $minPrice) {
                    return;
                }

                $clearPrice++;

                if ($row->quantity >= $minStock) {
                    $clearBoth++;
                }
            });

        return [$clearPrice, $clearBoth];
    }

    protected function queue(Supplier $supplier): void
    {
        $queue = 'import:'.$supplier->code;

        $waiting = DB::table('jobs')->where('queue', $queue)->count();
        $failed = DB::table('failed_jobs')->where('queue', $queue)->count();

        $this->newLine();
        $this->line('<options=bold>the queue</>');
        $this->line("  waiting: {$waiting}   failed: {$failed}");

        if ($waiting > 0) {
            $this->line('  <fg=yellow>The run is not finished. Count again in a few minutes — it should fall.</>');
        }

        if ($failed > 0) {
            $this->line("  <fg=yellow>Clear them with: php artisan import:restart {$supplier->code}</>");
        }

        if ($waiting === 0 && $failed === 0 && $supplier->last_run_at) {
            $this->line('  Nothing queued and nothing failed: the last run finished.');
        }
    }

    protected function quality(Supplier $supplier): void
    {
        $ids = ProductOffer::where('supplier_id', $supplier->id)->pluck('product_id')->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $products = Product::whereIn('id', $ids);

        $this->newLine();
        $this->line('<options=bold>what arrived</>');

        $this->table(['check', 'count'], [
            ['no category', (clone $products)->whereNull('category_id')->count()],
            ['no photograph', (clone $products)->whereDoesntHave('images')->count()],
            ['nothing in stock', (clone $products)->where('stock', '<=', 0)->count()],
            ['still a draft', (clone $products)->where('status', Product::STATUS_DRAFT)->count()],
            ['waiting in the mapping queue', DB::table('supplier_category_map')
                ->where('supplier_id', $supplier->id)
                ->whereNull('category_id')
                ->count()],
        ]);

        $this->checkImageFiles($ids);
    }

    protected function checkImageFiles($ids): void
    {
        $rows = DB::table('product_images')
            ->whereIn('product_id', $ids)
            ->inRandomOrder()
            ->limit(100)
            ->pluck('path');

        if ($rows->isEmpty()) {
            return;
        }

        $missing = $rows->reject(fn ($path) => Storage::disk('public')->exists($path))->count();

        if ($missing === 0) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=red>{$missing} of {$rows->count()} sampled photographs are recorded but not on disk.</>");
        $this->line('  Disk root: '.config('filesystems.disks.public.root'));
        $this->line('  Check that the directory exists and the web user can write to it.');
    }

    protected function share(int $part, int $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100).'%' : '—';
    }

    protected function yesNo(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
