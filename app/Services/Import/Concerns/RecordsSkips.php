<?php

namespace App\Services\Import\Concerns;

use App\Models\SupplierStock;

/*
 | A driver that returns nothing for a row leaves no trace of why, and a run of
 | several thousand then has no account of itself. This keeps the reason on the
 | row, where import:report can add it up.
 */
trait RecordsSkips
{
    protected function note(SupplierStock $row, ?string $reason): void
    {
        $data = (array) ($row->data ?? []);

        if (($data['skipped'] ?? null) === $reason) {
            return;
        }

        $data['skipped'] = $reason;

        $row->forceFill(['data' => array_filter($data, fn ($value) => $value !== null)])->saveQuietly();
    }
}
