<?php

namespace App\Services\Import\Drivers\Alta;

use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class AltaStockFeed
{
    public const MAX_AGE_HOURS = 26;

    public function snapshot(Supplier $supplier): array
    {
        $this->ensureAvailable($supplier);

        // Read the complete projection once so metadata describes the same
        // snapshot as the items, even while the scheduled refresh commits.
        $rows = DB::table('supplier_stocks')
            ->where('supplier_id', $supplier->id)
            ->orderBy('external_id')
            ->get(['external_id', 'quantity', 'synced_at']);

        $items = [];
        $oldest = null;

        foreach ($rows as $row) {
            $item = (string) $row->external_id;

            if (! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $item)) {
                continue;
            }

            $quantity = filter_var($row->quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($quantity === false || ! $row->synced_at) {
                throw new AltaUnavailable('stock_snapshot_unavailable', 503);
            }

            try {
                $synced = Carbon::parse($row->synced_at, config('app.timezone', 'UTC'));
            } catch (Throwable) {
                throw new AltaUnavailable('stock_snapshot_unavailable', 503);
            }

            if ($synced->gt(now()->addMinutes(5))) {
                throw new AltaUnavailable('stock_snapshot_unavailable', 503);
            }

            if ($oldest === null || $synced->lt($oldest)) {
                $oldest = $synced;
            }

            $items[] = ['item' => $item, 'quantity' => $quantity];
        }

        if ($items === [] || $oldest === null) {
            throw new AltaUnavailable('stock_snapshot_unavailable', 503);
        }

        if ($oldest->lt(now()->subHours(self::MAX_AGE_HOURS))) {
            throw new AltaUnavailable('stock_snapshot_stale', 503);
        }

        return [
            'schema' => 'iapi.supplier-stock.v1',
            'supplier' => 'alta',
            'source' => 'supplier_stocks',
            'synced_at' => $oldest->toIso8601String(),
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function link(Supplier $supplier): ?string
    {
        $fresh = $supplier->fresh();

        if (! $fresh || ! $fresh->is_active || $fresh->code !== 'alta' || $fresh->driver !== AltaDriver::class) {
            return null;
        }

        $version = $fresh->config['stock_feed_version'] ?? null;

        if (! is_string($version) || ! Str::isUuid($version)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').URL::signedRoute('feeds.alta-stock', ['version' => $version], absolute: false);
    }

    // The owner UI authorizes rotation. Lock and reread rather than writing a
    // stale model's config over another supplier-settings change.
    public function rotateLink(Supplier $supplier): string
    {
        return DB::transaction(function () use ($supplier) {
            $fresh = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureAvailable($fresh);
            $config = $fresh->config ?? [];
            $config['stock_feed_version'] = (string) Str::uuid();
            $fresh->update(['config' => $config]);

            return $this->link($fresh);
        });
    }

    protected function ensureAvailable(Supplier $supplier): void
    {
        if ($supplier->code !== 'alta' || ! $supplier->is_active || $supplier->driver !== AltaDriver::class) {
            throw new AltaUnavailable('supplier_unavailable', 503);
        }
    }
}
