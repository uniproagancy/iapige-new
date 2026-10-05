<?php

namespace App\Services\Import;

use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use InvalidArgumentException;

class ImportManager
{
    public function driver(Supplier $supplier): SupplierDriver
    {
        if (! class_exists($supplier->driver)) {
            throw new InvalidArgumentException("Driver [{$supplier->driver}] does not exist.");
        }

        return new $supplier->driver($supplier);
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
