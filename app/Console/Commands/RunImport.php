<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use Illuminate\Console\Command;

class RunImport extends Command
{
    protected $signature = 'import:run
                            {supplier? : the supplier code, e.g. zoommer}
                            {--all : run every active supplier}';

    protected $description = 'Queue a catalogue import for one supplier or all of them';

    public function handle(ImportManager $manager): int
    {
        $suppliers = $this->option('all')
            ? Supplier::active()->get()
            : Supplier::where('code', $this->argument('supplier'))->get();

        if ($suppliers->isEmpty()) {
            $this->error('No supplier matched. Pass a code or use --all.');

            return self::FAILURE;
        }

        foreach ($suppliers as $supplier) {
            $queued = $manager->run($supplier);
            $this->info("{$supplier->code}: {$queued} products queued on import:{$supplier->code}");
        }

        return self::SUCCESS;
    }
}
