<?php

namespace App\Services\Import;

use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use InvalidArgumentException;

class ImportManager
{
    /**
     * One driver per supplier for the life of this worker.
     *
     * A driver builds its HTTP clients in the constructor, and the import job
     * asks for one per product — so this was rebuilding the same clients, and
     * throwing away whatever they had cached in memory, thousands of times a
     * run.
     *
     * @var array<int, SupplierDriver>
     */
    protected array $drivers = [];

    public function driver(Supplier $supplier): SupplierDriver
    {
        if (! class_exists($supplier->driver)) {
            throw new InvalidArgumentException("Driver [{$supplier->driver}] does not exist.");
        }

        return $this->drivers[$supplier->id] ??= new $supplier->driver($supplier);
    }

    /** Queue one job per product, on the supplier's own queue. */
    public function run(Supplier $supplier): int
    {
        $queued = 0;

        foreach ($this->driver($supplier)->ids() as $externalId) {
            ImportProductJob::dispatch($supplier->id, (string) $externalId)
                ->onQueue('import:'.$supplier->code);

            $queued++;
        }

        $supplier->update(['last_run_at' => now()]);

        return $queued;
    }
}
