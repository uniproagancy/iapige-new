<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Why a supplier imported what it imported.
 *
 * A run that produces a tenth of what the price list holds is the normal
 * complaint, and answering it has meant three separate guesses: whether the
 * file loaded, whether the limits are cutting it, whether the queue is still
 * working. Each is a different query and none of them is obvious.
 *
 * So this counts the whole funnel in one pass — rows in, rows each rule lets
 * through, products that actually exist, jobs still waiting — and the step
 * where the number collapses is the answer.
 */
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

    /**
     * How long it takes this machine to reach the supplier at all.
     *
     * Every other figure in this report is about our own rules. None of them
     * can tell a slow import from an unreachable one, and the two look
     * identical from here: a run that crawls, and a log full of timeouts that
     * name no cause.
     *
     * The split is what matters. Connecting is DNS, routing and TLS; the rest
     * is the supplier thinking. A connect of twenty seconds is a network
     * problem and no amount of pacing or caching will touch it; a fast connect
     * and a slow answer is the supplier throttling, which pacing does fix.
     */
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

    /** The supplier's own host, however this one happens to be configured. */
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

    /**
     * What a run would actually produce, measured rather than guessed.
     *
     * Every number above this is a count of rows and rules. None of them can
     * say whether the supplier's own source will describe a row when asked —
     * and for the suppliers whose catalogue is larger than their website, that
     * is the figure that decides everything. INGCO's price list carries nine
     * thousand models and its site sells a fraction of them, so a run that
     * looked like it would import nine thousand imported a few hundred, over
     * several hours, with nothing to say why until it finished.
     *
     * So a handful of rows are put through the real driver — the same fetch an
     * import job makes — and what comes back is the hit rate. Thirty rows and
     * a minute, instead of the whole catalogue and an afternoon.
     */
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

    /* ------------------------------------------------------------------ settings */

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

        /*
         * The whole config, printed.
         *
         * A seeder only runs on a fresh database, so the row a supplier
         * actually holds drifts from the one the code was written against —
         * and every time it has, the symptom was somewhere else entirely. A
         * missing stock column made INGCO report one of everything; read_site
         * set to false made every Midea product arrive with no photograph and
         * a name assembled out of the spreadsheet. Neither was visible without
         * looking at this.
         */
        $this->line('  full config: '.json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->warnAboutDrift($supplier, $config);
    }

    /**
     * Settings a driver needs that this row does not carry.
     *
     * Only the ones whose absence is silent — a driver that reads a flag with
     * `?? true` behaves differently depending on whether the key is missing or
     * false, and nothing anywhere says which it was.
     */
    protected function warnAboutDrift(Supplier $supplier, array $config): void
    {
        /*
         * A supplier with no column map is not misconfigured.
         *
         * Some are fed by pasting addresses rather than by a spreadsheet, and
         * telling their owner that "the price list cannot be loaded at all"
         * sends them looking for a fault that is not there.
         */
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

    /* ------------------------------------------------------------------ the funnel */

    /**
     * Every step between the price list and a product, counted.
     *
     * The limits are read from the supplier's own config rather than assumed,
     * because they are a commercial decision somebody changes without telling
     * the code.
     */
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

        /*
         * The gap nothing else explains.
         *
         * Everything above is a rule we chose. What is left between the last
         * rule and the products we hold is the supplier's own site refusing to
         * describe them — a model the price list carries and the website does
         * not sell.
         */
        if ($offers < $clearBoth) {
            $missing = $clearBoth - $offers;

            $this->line("  <fg=yellow>{$missing} rows clear every rule and have no product.</>");
            $this->line('  Either the run has not finished, or the supplier’s site does not carry them.');
        }
    }

    /**
     * How many rows clear the price floor, and how many clear it and the stock.
     *
     * Counted in PHP rather than in SQL: the figure a floor is measured
     * against lives inside the stored row's JSON, and reaching into that is
     * written differently by every database — the query that worked on the
     * server returned "no such function" against the test's SQLite. A
     * diagnostic that only runs on one of the two is not much of a diagnostic.
     *
     * The price we pay is the promotional one where the list carries two, not
     * the list price beside it: measuring a floor against the wrong figure
     * lets a product through on a number nobody was charged.
     *
     * @return array{0: int, 1: int}
     */
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

    /* ------------------------------------------------------------------ the queue */

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

    /* ------------------------------------------------------------------ what arrived */

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

    /**
     * Photographs the database claims and the disk does not have.
     *
     * The public disk is configured not to throw, so a write it cannot make
     * returns false and says nothing. A product whose pictures could not be
     * stored still carried a row for every one of them, and every count in the
     * admin agreed it had photographs — the "no photo" filter counts rows, not
     * files. The shop then served broken images and nothing anywhere had
     * complained.
     *
     * A sample, because checking every file of every product is a lot of
     * stat() calls for a report. When the disk is the problem it is the
     * problem for all of them, so a hundred rows is plenty to see it.
     *
     * @param  Collection<int, int>  $ids
     */
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

    /* ------------------------------------------------------------------ helpers */

    protected function share(int $part, int $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100).'%' : '—';
    }

    protected function yesNo(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
