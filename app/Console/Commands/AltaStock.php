<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaStockSynchronizer;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Console\Command;

class AltaStock extends Command
{
    protected $signature = 'alta:stock';

    protected $description = 'Synchronize Alta B2B quantity without changing retail prices or requiring browser exports';

    public function handle(AltaStockSynchronizer $synchronizer): int
    {
        $supplier = Supplier::where('code', 'alta')->where('is_active', true)->first();

        if (! $supplier) {
            $this->line('Alta is inactive; stock was preserved.');

            return self::SUCCESS;
        }

        try {
            $this->line(json_encode($synchronizer->sync($supplier), JSON_THROW_ON_ERROR));
        } catch (AltaUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
