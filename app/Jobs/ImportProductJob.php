<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class ImportProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $supplierId,
        public string $externalId,
    ) {}

    /** Keeps us within whatever pace the source tolerates. */
    public function middleware(): array
    {
        return [new RateLimited('import')];
    }

    public function handle(ImportManager $manager, ProductImporter $importer): void
    {
        $supplier = Supplier::find($this->supplierId);

        if (! $supplier?->is_active) {
            return;
        }

        try {
            $payload = $manager->driver($supplier)->fetch($this->externalId);
        } catch (\Throwable $e) {
            // on the import channel with everything else, not the application log
            Log::channel('import')->warning('import fetch failed', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
                'error' => $e->getMessage(),
            ]);

            return;   // a single bad product must not stop the run
        }

        if (! $payload) {
            /*
             * Worth a line, because nothing else records it.
             *
             * The drivers that walk an id range ask for plenty of ids that do
             * not exist, so this is debug rather than info — but a run that
             * saves nothing at all used to leave no trace whatsoever, and
             * raising the channel to debug is now enough to see why.
             */
            Log::channel('import')->debug('nothing to import', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
            ]);

            return;
        }

        $importer->import($supplier, $payload);
    }
}
