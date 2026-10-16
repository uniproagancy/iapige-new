<?php

namespace App\Services\Import\Concerns;

use App\Models\ProductOffer;

trait ScansIdRange
{
    protected const DISCOVER_AHEAD = 1000;

    protected function idsToScan(int $defaultTo): iterable
    {
        $from = (int) ($this->supplier->config['from'] ?? 1);
        $to = (int) ($this->supplier->config['to'] ?? $defaultTo);

        $known = ProductOffer::where('supplier_id', $this->supplier->id)
            ->orderBy('external_id')
            ->pluck('external_id');

        if ($known->isEmpty() || ($this->supplier->config['full_scan'] ?? false)) {
            for ($id = $from; $id <= $to; $id++) {
                yield (string) $id;
            }

            return;
        }

        foreach ($known as $id) {
            yield (string) $id;
        }

        $highest = (int) $known->max(fn ($id) => (int) $id);
        $window = (int) ($this->supplier->config['discover_ahead'] ?? self::DISCOVER_AHEAD);

        $start = max($from, $highest + 1);
        $end = min($to, $highest + $window);

        for ($id = $start; $id <= $end; $id++) {
            yield (string) $id;
        }
    }
}
