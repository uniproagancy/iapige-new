<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Runs every supplier in one go.
 *
 * A shop with nine suppliers is nine commands typed in the right order, and
 * the order matters: a price list has to be loaded before the catalogue that
 * reads from it. This does both, for whichever suppliers are ready.
 */
class ImportAll extends Command
{
    protected $signature = 'import:all
                            {--only= : comma-separated supplier codes}
                            {--skip= : comma-separated supplier codes to leave out}
                            {--files : load price lists from storage/app/imports first}
                            {--sync : work the queue here instead of leaving jobs for a worker}';

    protected $description = 'Import every active supplier';

    /** Where a supplier's price list is expected, named after its code. */
    protected const FILES = 'imports';

    public function handle(): int
    {
        $suppliers = $this->suppliers();

        if ($suppliers->isEmpty()) {
            $this->error('No suppliers matched.');

            return self::FAILURE;
        }

        $this->line('Importing: '.$suppliers->pluck('code')->implode(', '));
        $this->newLine();

        $failed = [];

        foreach ($suppliers as $supplier) {
            $this->components->task($supplier->code, function () use ($supplier, &$failed) {
                try {
                    // a price list first: the catalogue run reads what it loaded
                    if ($this->option('files')) {
                        $this->loadFile($supplier);
                    }

                    Artisan::call('import:run', ['supplier' => $supplier->code], $this->output->getVerbosity() > 1 ? $this->output : null);

                    return true;
                } catch (\Throwable $e) {
                    $failed[$supplier->code] = $e->getMessage();

                    Log::channel('import')->error('supplier run failed', [
                        'supplier' => $supplier->code,
                        'error'    => $e->getMessage(),
                    ]);

                    return false;
                }
            });
        }

        $this->newLine();

        if ($this->option('sync')) {
            $this->workQueues($suppliers);
        } else {
            $this->line('Jobs are queued. Start a worker:');
            $this->line('  php artisan queue:work --queue='.$suppliers->map(fn ($s) => 'import:'.$s->code)->implode(','));
        }

        foreach ($failed as $code => $message) {
            $this->warn("{$code}: {$message}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Supplier> */
    protected function suppliers()
    {
        $only = $this->codes('only');
        $skip = $this->codes('skip');

        return Supplier::query()
            ->when(method_exists(Supplier::class, 'scopeActive'), fn ($q) => $q->active())
            ->when($only, fn ($q) => $q->whereIn('code', $only))
            ->when($skip, fn ($q) => $q->whereNotIn('code', $skip))
            ->orderBy('priority')
            ->get();
    }

    /** @return array<int, string> */
    protected function codes(string $option): array
    {
        $value = (string) $this->option($option);

        return $value === ''
            ? []
            : array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Loads the supplier's price list, if one is waiting.
     *
     * The file is looked for by the supplier's own code, so adding a supplier
     * means dropping a file named after it rather than editing this command.
     */
    protected function loadFile(Supplier $supplier): void
    {
        if (empty($supplier->config['columns'])) {
            return;   // this supplier has no spreadsheet to load
        }

        foreach (['xlsx', 'csv'] as $extension) {
            $path = storage_path(self::FILES."/{$supplier->code}.{$extension}");

            if (File::exists($path)) {
                Artisan::call('import:file', [
                    'supplier' => $supplier->code,
                    'file'     => $path,
                ]);

                return;
            }
        }

        $this->warn("  no price list for {$supplier->code}");
    }

    /**
     * Works the queues here and now.
     *
     * Useful on a machine with no supervisor, and when a person is watching and
     * wants to know the run has actually finished.
     */
    protected function workQueues($suppliers): void
    {
        $queues = $suppliers->map(fn ($s) => 'import:'.$s->code)->implode(',');

        $this->line("Working {$queues}…");

        Artisan::call('queue:work', [
            '--queue'            => $queues,
            '--stop-when-empty'  => true,
            '--tries'            => 3,
            '--sleep'            => 0,
        ], $this->output);
    }
}
