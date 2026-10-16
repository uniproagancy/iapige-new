<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestartImport extends Command
{
    protected $signature = 'import:restart
                            {supplier : the supplier code, e.g. zoommer}
                            {--force : skip the confirmation}';

    protected $description = 'Discard a supplier\'s queued and failed jobs, then queue the import again';

    public function handle(ImportManager $manager): int
    {
        if (! $supplier = Supplier::where('code', $this->argument('supplier'))->first()) {
            $this->error('No supplier with that code.');

            return self::FAILURE;
        }

        $queue = 'import:'.$supplier->code;

        $pending = DB::table('jobs')->where('queue', $queue)->count();
        $failed = DB::table('failed_jobs')->where('queue', $queue)->count();

        $this->line("{$queue}: {$pending} waiting, {$failed} failed");

        if (! $this->option('force') && ! $this->confirm('Discard those and queue the import again?', true)) {
            return self::SUCCESS;
        }

        DB::table('jobs')->where('queue', $queue)->delete();
        DB::table('failed_jobs')->where('queue', $queue)->delete();

        $this->info('  discarded');

        $queued = $manager->run($supplier);

        $this->info("  {$queued} products queued with today's rules");

        $this->call('queue:restart');
        $this->line('  workers asked to restart');

        return self::SUCCESS;
    }
}
