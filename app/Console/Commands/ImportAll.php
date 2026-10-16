<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ImportAll extends Command
{
    protected $signature = 'import:all
                            {--only= : comma-separated supplier codes}
                            {--skip= : comma-separated supplier codes to leave out}
                            {--files : load price lists from storage/app/imports or storage/imports first}
                            {--sync : work the queue here instead of leaving jobs for a worker}';

    protected $description = 'Import every active supplier';

    protected const FILE_DIRS = ['app/imports', 'imports'];

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

                    if ($this->option('files')) {
                        $this->loadFile($supplier);
                    }

                    $code = Artisan::call('import:run', ['supplier' => $supplier->code], $this->output->getVerbosity() > 1 ? $this->output : null);

                    if ($code !== self::SUCCESS) {
                        $failed[$supplier->code] = 'import:run exited with '.$code.' — see the output above';

                        return TaskResult::Failure->value;
                    }

                    return TaskResult::Success->value;
                } catch (\Throwable $e) {
                    $failed[$supplier->code] = $e->getMessage();

                    Log::channel('import')->error('supplier run failed', [
                        'supplier' => $supplier->code,
                        'error' => $e->getMessage(),
                    ]);

                    return TaskResult::Failure->value;
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

    protected function codes(string $option): array
    {
        $value = (string) $this->option($option);

        return $value === ''
            ? []
            : array_filter(array_map('trim', explode(',', $value)));
    }

    protected function loadFile(Supplier $supplier): void
    {
        if (empty($supplier->config['columns'])) {
            return;
        }

        foreach (self::FILE_DIRS as $dir) {
            foreach (['xlsx', 'csv'] as $extension) {
                $path = storage_path("{$dir}/{$supplier->code}.{$extension}");

                if (File::exists($path)) {
                    Artisan::call('import:file', [
                        'supplier' => $supplier->code,
                        'file' => $path,
                    ]);

                    return;
                }
            }
        }

        $this->warn("  no price list for {$supplier->code}: expected storage/"
            .self::FILE_DIRS[0]."/{$supplier->code}.xlsx");
    }

    protected function workQueues($suppliers): void
    {
        $queues = $suppliers->map(fn ($s) => 'import:'.$s->code)->implode(',');

        $this->line("Working {$queues}…");

        Artisan::call('queue:work', [
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--tries' => 3,
            '--sleep' => 0,
        ], $this->output);
    }
}
