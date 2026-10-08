<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Throw away a supplier's queued work and start it again.
 *
 * Laravel writes maxTries and retryUntil into a job's payload at the moment
 * it is queued, so a job dispatched before those were corrected keeps obeying
 * the old rules however the class reads now — and queue:retry re-sends that
 * same payload, which is why a fix can look like it changed nothing.
 *
 * The cure is to discard and re-dispatch, which is three commands in the
 * right order with the right queue name. This is those three, so the order
 * cannot be got wrong and the old payloads cannot survive.
 */
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

        /*
         * A daemon worker keeps the old class in memory, so it would carry on
         * importing with the code it started under.
         */
        $this->call('queue:restart');
        $this->line('  workers asked to restart');

        return self::SUCCESS;
    }
}
