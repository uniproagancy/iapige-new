<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaItemLookup;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AltaItem extends Command
{
    protected $signature = 'alta:item {item : B2B item code, or the sku as alta-171840}';

    protected $description = 'Show what Alta B2B says right now about one item, next to what the site holds. Changes nothing.';

    public function handle(AltaItemLookup $lookup): int
    {
        $supplier = Supplier::where('code', 'alta')->firstOrFail();

        try {
            $answer = $lookup->check($supplier, (string) $this->argument('item'));
        } catch (AltaUnavailable|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
