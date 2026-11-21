<?php

namespace App\Services\Procurement;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\ProcurementPostalOrder;
use App\Models\User;
use App\Services\Delivery\SupplierItemIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProcurementPostal
{
    public const LIMIT = 500;

    public const CANDIDATE_LIMIT = 200;

    public function isInstalled(): bool
    {
        return Schema::hasTable('procurement_postal_orders');
    }

    public function states(User $actor, array $orderIds): array
    {
        $this->admin($actor);
        Validator::make(['ids' => $orderIds], [
            'ids' => ['array', 'max:500'], 'ids.*' => ['integer', 'min:1'],
        ])->validate();
        if (! $this->isInstalled() || $orderIds === []) {
            return [];
        }

        return $this->rows(array_values(array_unique(array_map('intval', $orderIds))));
    }

    public function board(User $actor, array $filters = []): array
    {
        $this->admin($actor);
        $filters = $this->filters($filters);
        if (! $this->isInstalled()) {
            return ['rows' => [], 'counts' => [], 'total' => 0, 'truncated' => false, 'installed' => false];
        }
        $base = $this->recordFilters(ProcurementPostalOrder::where('selected', true), $filters);
        $totals = (clone $base)->selectRaw('COUNT(*) AS postal_total,
            SUM(CASE WHEN picked_up_at IS NULL AND uploaded_at IS NULL THEN 1 ELSE 0 END) AS postal_waiting,
            SUM(CASE WHEN picked_up_at IS NOT NULL AND uploaded_at IS NULL THEN 1 ELSE 0 END) AS postal_picked,
            SUM(CASE WHEN uploaded_at IS NOT NULL THEN 1 ELSE 0 END) AS postal_uploaded')->first();
        $query = clone $base;
        $status = $filters['status'] ?? 'all';
        if ($status === 'waiting') {
            $query->whereNull('picked_up_at')->whereNull('uploaded_at');
        } elseif ($status === 'picked_up') {
            $query->whereNotNull('picked_up_at')->whereNull('uploaded_at');
        } elseif ($status === 'uploaded') {
            $query->whereNotNull('uploaded_at');
        }
        $ids = $query->orderBy('id')->limit(self::LIMIT + 1)->pluck('order_id');
        $rows = $this->rows($ids->take(self::LIMIT)->all());
        $counts = ['all' => (int) $totals->postal_total, 'waiting' => (int) $totals->postal_waiting,
            'picked_up' => (int) $totals->postal_picked, 'uploaded' => (int) $totals->postal_uploaded,
            'changed' => 0, 'unavailable' => 0];
        foreach ($rows as $row) {
            $counts['changed'] += (int) $row['source_changed'];
            $counts['unavailable'] += (int) ! $row['available'];
        }
        $rows = array_values(array_filter($rows, fn ($row) => match ($status) {
            'changed' => $row['source_changed'],
            'unavailable' => ! $row['available'],
            default => true,
        }));
        usort($rows, fn ($a, $b) => strcmp($a['delivery_date'], $b['delivery_date']) ?: ($a['order_id'] <=> $b['order_id']));

        return ['rows' => $rows, 'counts' => $counts, 'total' => count($rows),
            'truncated' => $ids->count() > self::LIMIT,
            'counts_truncated' => $ids->count() > self::LIMIT || in_array($status, ['waiting', 'picked_up', 'uploaded'], true),
            'installed' => true];
    }

    public function candidates(User $actor, array $filters = []): array
    {
        $this->admin($actor);
        unset($filters['status']);
        $filters = $this->filters($filters);
        if (! $this->isInstalled()) {
            return ['rows' => [], 'total' => 0, 'truncated' => false, 'installed' => false];
        }
        $query = OrderDelivery::query()->where('is_eligible', true)->where('status', '!=', OrderDelivery::CANCELLED)
            ->whereHas('order', fn (Builder $q) => $q->withoutTrashed()->where('status', Order::COMPLETED)->whereHas('items'))
            ->whereNotIn('order_id', ProcurementPostalOrder::where('selected', true)->select('order_id'));
        $this->deliveryFilters($query, $filters);
        $ids = $query->orderBy('delivery_date')->orderBy('id')->limit(self::CANDIDATE_LIMIT + 1)->pluck('order_id');
        $rows = array_values(array_filter($this->rows($ids->take(self::CANDIDATE_LIMIT)->all()), fn ($row) => $row['available'] && ! $row['selected']));

        return ['rows' => $rows, 'total' => count($rows),
            'truncated' => $ids->count() > self::CANDIDATE_LIMIT, 'installed' => true];
    }

    public function select(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'select');
    }

    public function deselect(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'deselect');
    }

    public function markPickedUp(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'picked_up');
    }

    public function markUploaded(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'uploaded');
    }

    public function undoUploaded(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'undo_uploaded');
    }

    public function undoPickedUp(User $actor, int $orderId, string $expectedHash): void
    {
        $this->change($actor, $orderId, $expectedHash, 'undo_picked_up');
    }

    protected function change(User $actor, int $orderId, string $expectedHash, string $kind): void
    {
        $this->admin($actor);
        Validator::make(['id' => $orderId, 'hash' => $expectedHash], [
            'id' => ['required', 'integer', 'min:1'], 'hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
        ])->validate();
        if (! $this->isInstalled()) {
            $this->invalid('not_installed');
        }
        DB::transaction(function () use ($actor, $orderId, $expectedHash, $kind) {
            $actor = $this->admin($actor);
            $order = Order::withTrashed()->whereKey($orderId)->lockForUpdate()->first();
            $delivery = OrderDelivery::where('order_id', $orderId)->lockForUpdate()->first();
            $record = ProcurementPostalOrder::where('order_id', $orderId)->lockForUpdate()->first();
            $snapshot = $this->snapshot($order, $delivery, $record);
            $row = $this->row($orderId, $snapshot, $record);
            if (in_array($kind, ['select', 'picked_up', 'uploaded'], true) && ! $row['available']) {
                $this->invalid('unavailable');
            }
            if ($this->already($kind, $row, $record)) {
                return;
            }
            if (! hash_equals($row['expected_hash'], $expectedHash)) {
                $this->invalid('changed');
            }
            if ($kind !== 'select' && (! $record || ! $record->selected)) {
                $this->invalid('not_selected');
            }
            if ($kind === 'uploaded' && ! $record?->picked_up_at) {
                $this->invalid('pickup_required');
            }
            if ($kind === 'undo_picked_up' && $record?->uploaded_at) {
                $this->invalid('uploaded_first');
            }
            $record ??= new ProcurementPostalOrder(['order_id' => $orderId, 'version' => 0, 'created_by' => $actor->id]);
            $now = now();
            $attributes = ['updated_by' => $actor->id, 'version' => $record->version + 1];
            if ($kind === 'select') {
                $attributes += ['selected' => true, 'selected_at' => $now, 'selected_by' => $actor->id];
            } elseif ($kind === 'deselect') {
                $attributes['selected'] = false;
            } elseif ($kind === 'picked_up') {
                $attributes += ['picked_up_at' => $now, 'picked_up_by' => $actor->id, 'picked_up_source_hash' => $snapshot['_source_hash']];
            } elseif ($kind === 'uploaded') {
                $attributes += ['uploaded_at' => $now, 'uploaded_by' => $actor->id, 'uploaded_source_hash' => $snapshot['_source_hash']];
            } elseif ($kind === 'undo_uploaded') {
                $attributes += ['uploaded_at' => null, 'uploaded_by' => null, 'uploaded_source_hash' => null];
            } elseif ($kind === 'undo_picked_up') {
                $attributes += ['picked_up_at' => null, 'picked_up_by' => null, 'picked_up_source_hash' => null];
            }
            if (in_array($kind, ['select', 'picked_up', 'uploaded'], true)) {
                $attributes['source_snapshot'] = $snapshot;
            }
            $history = $record->history ?? [];
            $history[] = ['kind' => $kind, 'actor_id' => $actor->id, 'at' => $now->toIso8601String(), 'source_hash' => $snapshot['_source_hash']];
            $attributes['history'] = array_slice($history, -100);
            $record->fill($attributes)->save();
        }, 3);
    }

    protected function already(string $kind, array $row, ?ProcurementPostalOrder $record): bool
    {
        return match ($kind) {
            'select' => $row['selected'],
            'deselect' => $record !== null && ! $row['selected'],
            'picked_up' => $record?->picked_up_at !== null && $record?->picked_up_source_hash === $row['source_hash'],
            'uploaded' => $record?->uploaded_at !== null && $record?->uploaded_source_hash === $row['source_hash'],
            'undo_uploaded' => $record !== null && $record->uploaded_at === null,
            'undo_picked_up' => $record !== null && $record->picked_up_at === null,
            default => false,
        };
    }

    protected function rows(array $ids): array
    {
        $records = ProcurementPostalOrder::whereIn('order_id', $ids)->get()->keyBy('order_id');
        $orders = Order::withTrashed()->with('items')->whereIn('id', $ids)->get()->keyBy('id');
        $deliveries = OrderDelivery::with(['items.supplier', 'items.product.translations'])->whereIn('order_id', $ids)->get()->keyBy('order_id');
        $rows = [];
        foreach ($ids as $id) {
            $snapshot = $this->snapshot($orders->get($id), $deliveries->get($id), $records->get($id));
            $rows[$id] = $this->row((int) $id, $snapshot, $records->get($id));
        }

        return $rows;
    }

    protected function snapshot(?Order $order, ?OrderDelivery $delivery, ?ProcurementPostalOrder $record): array
    {
        if (! $order) {
            $snapshot = $record?->source_snapshot ?? ['number' => '', 'name' => '', 'phone' => '',
                'address' => '', 'city' => '', 'delivery_date' => '', 'items' => [],
                'payment' => '', 'payment_bank' => '', 'total' => '0.00', 'is_paid' => false];
            $snapshot['available'] = false;
            $snapshot['source_reason'] = 'deleted';
            $snapshot['_source_hash'] = $this->hash([$snapshot['_source_hash'] ?? null, 'missing']);

            return $snapshot;
        }
        $order->loadMissing('items');
        $delivery?->loadMissing(['items.supplier', 'items.product.translations']);
        $items = [];
        foreach ($order->items as $item) {
            $source = $delivery?->items->firstWhere('order_item_id', $item->id);
            $native = $source ? app(SupplierItemIdentity::class)->resolve($source)['native_sku'] : null;
            $items[] = ['product_id' => $item->product_id, 'sku' => (string) $item->sku,
                'name' => (string) $item->name, 'qty' => (int) $item->qty,
                'price' => (string) $item->price, 'sum' => (string) $item->sum,
                'supplier_id' => $source?->supplier_id, 'supplier_name' => (string) ($source?->supplier_name ?? ''),
                'supplier_sku' => (string) ($native ?? '')];
        }
        usort($items, fn ($a, $b) => strcmp($this->hash($a), $this->hash($b)));
        $available = ! $order->trashed() && $order->status === Order::COMPLETED && $delivery !== null
            && $delivery->status !== OrderDelivery::CANCELLED && $delivery->is_eligible && $items !== [];
        $snapshot = ['number' => (string) $order->number, 'name' => (string) $order->name,
            'phone' => (string) $order->phone, 'address' => (string) $order->address,
            'city' => (string) $order->city, 'delivery_date' => $delivery?->delivery_date?->format('Y-m-d') ?? '',
            'items' => $items, 'payment' => (string) $order->payment, 'payment_bank' => (string) $order->payment_bank,
            'total' => (string) $order->total, 'is_paid' => (bool) $order->is_paid,
            'available' => $available, 'source_reason' => $available ? '' : ($order->trashed() ? 'deleted' : 'unavailable'),
            'cancel_event_id' => (int) $order->events()->where('type', 'status')->whereIn('to', [Order::CANCELLED, 'deleted'])->max('id')];
        $snapshot['_source_hash'] = $this->hash($snapshot);

        return $snapshot;
    }

    protected function row(int $id, array $snapshot, ?ProcurementPostalOrder $record): array
    {
        $hash = $snapshot['_source_hash'];
        $anchor = $record?->uploaded_source_hash ?: $record?->picked_up_source_hash ?: ($record?->source_snapshot['_source_hash'] ?? null);
        $changed = $record !== null && $anchor !== null && ! hash_equals($anchor, $hash);
        $status = $record?->uploaded_at ? 'uploaded' : ($record?->picked_up_at ? 'picked_up' : 'waiting');

        return [...$snapshot, 'order_id' => $id, 'selected' => (bool) ($record?->selected ?? false),
            'status' => $status, 'source_hash' => $hash, 'source_changed' => $changed,
            'version' => (int) ($record?->version ?? 0),
            'picked_up_at' => $record?->picked_up_at?->toIso8601String(),
            'uploaded_at' => $record?->uploaded_at?->toIso8601String(),
            'expected_hash' => $this->hash([$id, $hash, $record?->id, $record?->version ?? 0,
                $record?->selected ?? false, $record?->picked_up_source_hash, $record?->uploaded_source_hash])];
    }

    protected function recordFilters(Builder $query, array $filters): Builder
    {
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $query->where(fn ($q) => $q->whereHas('delivery', fn ($d) => $d->whereDate('delivery_date', $operator, $filters[$key]))
                    ->orWhere(fn ($missing) => $missing->whereDoesntHave('delivery')->where('source_snapshot->delivery_date', $operator, $filters[$key])));
            }
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.trim($filters['q']).'%';
            $query->where(fn ($q) => $q->whereHas('order', fn ($o) => $o->where(fn ($s) => $s->where('number', 'like', $term)->orWhere('name', 'like', $term)->orWhere('phone', 'like', $term)))
                ->orWhere('source_snapshot->number', 'like', $term)->orWhere('source_snapshot->name', 'like', $term)->orWhere('source_snapshot->phone', 'like', $term));
        }

        return $query;
    }

    protected function deliveryFilters(Builder $query, array $filters): void
    {
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $query->whereDate('delivery_date', $operator, $filters[$key]);
            }
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.trim($filters['q']).'%';
            $query->whereHas('order', fn ($o) => $o->where(fn ($s) => $s->where('number', 'like', $term)->orWhere('name', 'like', $term)->orWhere('phone', 'like', $term)));
        }
    }

    protected function filters(array $filters): array
    {
        return Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...(filled($filters['date_from'] ?? null) ? ['after_or_equal:date_from'] : [])],
            'status' => ['nullable', 'in:all,waiting,picked_up,uploaded,changed,unavailable'],
        ])->validate();
    }

    protected function admin(User $actor): User
    {
        $actor = User::find($actor->id);
        abort_unless($actor?->isAdmin(), 403);

        return $actor;
    }

    protected function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function invalid(string $key): never
    {
        throw ValidationException::withMessages(['postal' => __('procurement_postal.errors.'.$key)]);
    }
}
