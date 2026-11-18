<?php

namespace App\Services\Procurement;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\ProcurementEvent;
use App\Models\ProcurementItem;
use App\Models\ProcurementTask;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\SupplierItemIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProcurementBoard
{
    public const LIMIT = 500;

    public const STATUSES = ['unsent', 'sent', 'confirmed', 'received', 'issue'];

    public function isInstalled(): bool
    {
        return Schema::hasTable('procurement_items') && Schema::hasTable('procurement_tasks')
            && Schema::hasTable('procurement_events');
    }

    /** Source reconciliation is a read: no courier actions, synchronization, or procurement writes. */
    public function board(User $actor, array $filters = []): array
    {
        $this->admin($actor);
        $filters = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:all,outstanding,unsent,sent,confirmed,received,issue,withdraw'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...(filled($filters['date_from'] ?? null) ? ['after_or_equal:date_from'] : [])],
        ])->validate();
        if (! $this->isInstalled()) {
            return ['groups' => [], 'total' => 0, 'truncated' => false, 'counts' => [], 'suppliers' => [], 'installed' => false];
        }
        $recordQuery = $this->filterRecords(ProcurementItem::query(), $filters);
        // Changed/missing sent sources remain actionable even after hundreds of healthy records.
        $attention = $this->attentionRecords(clone $recordQuery)
            ->orderBy('id')->limit(self::LIMIT + 1)->get();
        $records = $this->filterRecordStatus(clone $recordQuery, $filters['status'] ?? 'outstanding')
            ->orderByRaw("CASE WHEN status = 'received' THEN 1 ELSE 0 END")
            ->orderBy('id')->limit(self::LIMIT + 1)->get();
        $recordOverflow = $records->count() > self::LIMIT || $attention->count() > self::LIMIT;
        $records = $attention->take(self::LIMIT)->concat($records->take(self::LIMIT))
            ->unique('order_delivery_item_id')->keyBy('order_delivery_item_id');
        $taskQuery = $this->filterSnapshot(ProcurementTask::whereNull('acknowledged_at'), 'snapshot', $filters);
        $tasks = $taskQuery->orderBy('id')->limit(self::LIMIT + 1)->get();
        $taskOverflow = $tasks->count() > self::LIMIT;
        $tasks = $tasks->take(self::LIMIT);
        $candidateQuery = OrderDeliveryItem::query()->where('is_active', true)
            ->whereHas('delivery', function (Builder $delivery) {
                $delivery->where('status', OrderDelivery::ACTIVE)->where('is_eligible', true)
                    ->whereHas('order', fn (Builder $order) => $order->withoutTrashed()->where('status', Order::COMPLETED));
            });
        // Filter before each bounded read; a later supplier/day cannot lose its saved progress.
        $candidateQuery = $this->filterSources($candidateQuery, $filters);
        $candidateIds = $candidateQuery->orderBy('id')->limit(self::LIMIT + 1)->pluck('id');
        $candidateOverflow = $candidateIds->count() > self::LIMIT;
        $selectedRecords = ProcurementItem::whereIn('order_delivery_item_id', $candidateIds->take(self::LIMIT))->get();
        $records = $records->concat($selectedRecords)->keyBy('order_delivery_item_id');
        // Check all obligations for these bounded records, even when an older task lies outside this view's filters.
        $pendingRecordIds = ProcurementTask::whereIn('procurement_item_id', $records->pluck('id'))
            ->whereNull('acknowledged_at')->distinct()->pluck('procurement_item_id')->flip()->all();
        $ids = $candidateIds->take(self::LIMIT)->merge($records->keys())->unique()->values();
        $sources = OrderDeliveryItem::with(['delivery.order', 'supplier', 'product.translations'])
            ->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
        $allRows = [];
        foreach ($tasks as $task) {
            $allRows[$this->taskRowKey($task->snapshot['item_id'], $task->task_key)] = $this->taskRow($task->task_key, $task->snapshot);
        }
        foreach ($records as $record) {
            $snapshot = $this->snapshot($sources->get($record->order_delivery_item_id), $record);
            if ($this->needsWithdrawal($record, $snapshot)) {
                $taskKey = $this->taskKey($record);
                $allRows[$this->taskRowKey($record->order_delivery_item_id, $taskKey)] = $this->taskRow($taskKey, $record->last_sent_snapshot);
            }
        }
        // Outstanding old-supplier tasks are placed before current requests, independently of delivery date.
        foreach ($sources as $item) {
            $record = $records->get($item->id);
            $snapshot = $this->snapshot($item, $record);
            if ($record || $snapshot['available']) {
                $allRows['current:'.$item->id] = $this->currentRow($snapshot, $record, $item,
                    $record && isset($pendingRecordIds[$record->id]));
            }
        }
        foreach ($records as $record) {
            if (! $sources->has($record->order_delivery_item_id)) {
                $snapshot = $this->snapshot(null, $record);
                $allRows['current:'.$record->order_delivery_item_id] = $this->currentRow($snapshot, $record, null,
                    isset($pendingRecordIds[$record->id]));
            }
        }
        $matching = array_values(array_filter($allRows, fn (array $row) => $this->matches($row, $filters)));
        $priority = ['withdraw' => 0, 'issue' => 1, 'unsent' => 2, 'sent' => 3, 'confirmed' => 3, 'received' => 4];
        usort($matching, fn (array $a, array $b) => ($priority[$a['status']] <=> $priority[$b['status']])
            ?: strcmp($a['key'], $b['key']));
        $total = count($matching);
        $rows = array_slice($matching, 0, self::LIMIT);
        $counts = array_fill_keys([...self::STATUSES, 'withdraw'], 0);
        $groups = [];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
            $groupKey = $this->hash([$row['supplier_id'], $row['supplier_code'], $row['supplier_name']]);
            $groups[$groupKey] ??= ['key' => $groupKey, 'name' => $row['supplier_name'] ?: __('procurement.unknown_supplier'),
                'supplier_id' => $row['supplier_id'], 'rows' => [], 'summary' => [], 'manifest' => [], 'copy_text' => ''];
            $groups[$groupKey]['rows'][] = $row;
        }
        foreach ($groups as &$group) {
            $summaries = [];
            $requests = [];
            $withdrawals = [];
            foreach ($group['rows'] as $row) {
                $identity = $this->hash([$row['supplier_id'], $row['supplier_code'], $row['product_id'], $row['sku'],
                    $row['model'], $row['code'], $row['code_label'], $row['system_reference'], $row['type']]);
                if ($row['type'] === 'current') {
                    $summaries[$identity] ??= ['key' => $identity, 'model' => $row['model'], 'code' => $row['code'],
                        'code_label' => $row['code_label'], 'qty' => 0, 'item_count' => 0, 'order_manifest' => [],
                        'statuses' => array_fill_keys(self::STATUSES, 0), 'status' => $row['status']];
                    $summaries[$identity]['qty'] += $row['qty'];
                    $summaries[$identity]['item_count']++;
                    $summaries[$identity]['statuses'][$row['status']]++;
                    if ($row['available'] && $row['status'] === 'unsent') {
                        $summaries[$identity]['order_manifest'][] = ['key' => $row['key'], 'expected_hash' => $row['expected_hash']];
                    }
                    $activeStatuses = array_keys(array_filter($summaries[$identity]['statuses']));
                    $summaries[$identity]['status'] = count($activeStatuses) === 1 ? $activeStatuses[0] : 'mixed';
                }
                if ($row['type'] === 'withdraw' || ($row['available'] && $row['status'] === 'unsent')) {
                    $group['manifest'][] = ['key' => $row['key'], 'expected_hash' => $row['expected_hash']];
                    if ($row['type'] === 'withdraw') {
                        $withdrawals[$identity] ??= $row;
                        if ($withdrawals[$identity]['key'] !== $row['key']) {
                            $withdrawals[$identity]['qty'] += $row['qty'];
                        }
                    } else {
                        $requests[$identity] ??= $row;
                        if ($requests[$identity]['key'] !== $row['key']) {
                            $requests[$identity]['qty'] += $row['qty'];
                        }
                    }
                }
            }
            $group['summary'] = array_values($summaries);
            $text = [];
            foreach ($withdrawals as $row) {
                $text[] = __('procurement.copy.withdraw').' '.$this->copyLine($row);
            }
            foreach ($requests as $row) {
                $text[] = $this->copyLine($row);
            }
            $group['copy_text'] = implode("\n", $text);
        }
        unset($group);
        uasort($groups, fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return ['groups' => array_values($groups), 'total' => $total,
            'truncated' => $recordOverflow || $taskOverflow || $candidateOverflow || $total > self::LIMIT,
            'counts' => $counts, 'installed' => true,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->limit(self::LIMIT)
                ->get(['id', 'name'])->map(fn (Supplier $supplier) => ['id' => (int) $supplier->id, 'name' => $supplier->name])->all()];
    }

    /** The reviewed manifest, not the current live group membership, defines this explicit action. */
    public function markSent(User $actor, array $manifest): void
    {
        $this->admin($actor);
        Validator::make(['manifest' => $manifest], ['manifest' => ['required', 'array', 'min:1', 'max:500'],
            'manifest.*' => ['required', 'array:key,expected_hash'], 'manifest.*.key' => ['required', 'string', 'distinct', 'max:100'],
            'manifest.*.expected_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D']])->validate();
        usort($manifest, fn (array $a, array $b) => strcmp($a['key'], $b['key']));
        DB::transaction(function () use ($actor, $manifest) {
            $actor = $this->admin($actor, true);
            $prepared = [];
            // Validate everything before any messages are recorded as sent.
            foreach ($manifest as $entry) {
                if (preg_match('/\Acurrent:([1-9][0-9]*)\z/D', $entry['key'], $match)) {
                    [$item, $record, $snapshot] = $this->context((int) $match[1]);
                    $this->expected($entry['expected_hash'], $this->rowHash($snapshot, $record));
                    if (! $snapshot['available'] || $this->effectiveStatus($snapshot, $record) !== 'unsent') {
                        $this->fail('changed');
                    }
                    $prepared[] = ['current', $item, $record, $snapshot];
                } else {
                    $prepared[] = ['task', ...$this->taskContext($entry['key'], $entry['expected_hash'])];
                }
            }
            // Withdrawals are acknowledged first so an old message cannot be resurrected during resend.
            foreach ($prepared as $action) {
                if ($action[0] === 'task') {
                    $this->acknowledgeTask($action[1], $action[2], $action[3], $actor);
                }
            }
            foreach ($prepared as $action) {
                if ($action[0] !== 'current') {
                    continue;
                }
                [, $item, $record, $snapshot] = $action;
                // A sibling withdrawal may have updated the same private row in this transaction.
                $record = ProcurementItem::where('order_delivery_item_id', $item->id)->lockForUpdate()->first() ?? $record;
                $record = $this->record($item->id, $record, $actor);
                $this->preserveWithdrawal($record, $snapshot);
                $record->fill(['last_sent_snapshot' => $snapshot, 'source_snapshot' => $snapshot, 'status' => 'sent',
                    'sent_version' => $record->sent_version + 1, 'version' => $record->version + 1,
                    'issue_qty' => null, 'updated_by' => $actor->id])->save();
                $this->event($record, 'sent', $actor, ['snapshot' => $snapshot, 'sent_version' => $record->sent_version]);
            }
        }, 3);
    }

    public function updateItem(User $actor, int $itemId, string $expectedHash, array $values): void
    {
        $this->admin($actor);
        if (array_diff(array_keys($values), ['status', 'note', 'native_sku', 'issue_qty']) !== []) {
            $this->fail('invalid');
        }
        $values = Validator::make($values, ['status' => ['sometimes', 'required', 'in:unsent,sent,confirmed,received,issue'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'native_sku' => ['sometimes', 'nullable', 'string', 'max:191', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'issue_qty' => ['sometimes', 'nullable', 'integer', 'min:0']])->validate();
        DB::transaction(function () use ($actor, $itemId, $expectedHash, $values) {
            $actor = $this->admin($actor, true);
            [$item, $record, $snapshot] = $this->context($itemId);
            $this->expected($expectedHash, $this->rowHash($snapshot, $record));
            if (! $snapshot['available'] || ! $item) {
                $this->fail('unavailable');
            }
            if (($values['issue_qty'] ?? 0) > $snapshot['qty']) {
                $this->fail('quantity');
            }
            // An existing sent state may accompany a note edit; a form cannot manufacture a send.
            if (($values['status'] ?? null) === 'sent' && $this->effectiveStatus($snapshot, $record) !== 'sent') {
                $this->fail('invalid');
            }
            $record = $this->record($itemId, $record, $actor);
            $record->source_snapshot ??= $snapshot;
            $before = $record->only(['status', 'note', 'native_sku', 'issue_qty']);
            $commitmentRecorded = false;
            $nativeChanged = array_key_exists('native_sku', $values)
                && (trim((string) $values['native_sku']) ?: null) !== $record->native_sku;
            if ($nativeChanged || array_key_exists('status', $values)) {
                $this->preserveWithdrawal($record, $snapshot);
            }
            if (array_key_exists('note', $values)) {
                $record->note = trim((string) $values['note']) ?: null;
            }
            if (array_key_exists('native_sku', $values)) {
                $record->native_sku = trim((string) $values['native_sku']) ?: null;
                $record->native_identity_hash = $record->native_sku ? $this->nativeIdentity($snapshot) : null;
            }
            if (array_key_exists('issue_qty', $values)) {
                $record->issue_qty = (int) ($values['issue_qty'] ?? 0) ?: null;
            }
            if ($nativeChanged || array_key_exists('status', $values)) {
                $snapshot = $this->snapshot($item, $record);
                $record->source_snapshot = $snapshot;
                $record->status = $nativeChanged && ($values['status'] ?? null) === 'sent'
                    ? 'unsent' : ($values['status'] ?? 'unsent');
                // The owner may confirm an order arranged elsewhere without using our copy/send action.
                if (in_array($record->status, ['confirmed', 'received'], true)
                    && (! $this->hasSent($record) || ! $this->same($record->last_sent_snapshot, $snapshot))) {
                    $this->preserveWithdrawal($record, $snapshot);
                    $record->last_sent_snapshot = $snapshot;
                    $record->sent_version++;
                    $commitmentRecorded = true;
                }
            }
            if (! $record->isDirty()) {
                return;
            }
            $record->version++;
            $record->updated_by = $actor->id;
            $record->save();
            $this->event($record, 'updated', $actor, ['before' => $before,
                'after' => $record->only(['status', 'note', 'native_sku', 'issue_qty'])]);
            if ($commitmentRecorded) {
                $this->event($record, 'commitment_recorded', $actor, ['snapshot' => $record->last_sent_snapshot,
                    'status' => $record->status, 'sent_version' => $record->sent_version]);
            }
        }, 3);
    }

    public function acknowledge(User $actor, string $rowKey, string $expectedHash): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $rowKey, $expectedHash) {
            $actor = $this->admin($actor, true);
            [$record, $taskKey, $snapshot] = $this->taskContext($rowKey, $expectedHash);
            $this->acknowledgeTask($record, $taskKey, $snapshot, $actor);
        }, 3);
    }

    public function changeSupplier(User $actor, int $itemId, string $expectedHash, int $supplierId): void
    {
        $this->admin($actor);
        Validator::make(['supplier_id' => $supplierId], ['supplier_id' => ['required', 'integer', 'min:1', 'exists:suppliers,id']])->validate();
        DB::transaction(function () use ($actor, $itemId, $expectedHash, $supplierId) {
            $actor = $this->admin($actor, true);
            [$item, $record, $snapshot] = $this->context($itemId);
            $this->expected($expectedHash, $this->rowHash($snapshot, $record));
            if (! $item || ! $snapshot['available'] || $item->delivery->status !== OrderDelivery::ACTIVE) {
                $this->fail('unavailable');
            }
            if ((int) $item->supplier_id === $supplierId) {
                return;
            }
            $record = $this->record($itemId, $record, $actor);
            $this->preserveWithdrawal($record, null);
            app(ProcurementSupplierEditor::class)->apply($item, $supplierId, $actor);
            $item = $this->load($itemId);
            $record->native_sku = null;
            $record->native_identity_hash = null;
            $newSnapshot = $this->snapshot($item, $record);
            $record->fill(['status' => 'unsent', 'source_snapshot' => $newSnapshot, 'issue_qty' => null,
                'version' => $record->version + 1, 'updated_by' => $actor->id])->save();
            $this->event($record, 'supplier_changed', $actor, ['from' => $snapshot['supplier_id'], 'to' => $supplierId]);
        }, 3);
    }

    private function admin(User $actor, bool $lock = false): User
    {
        $current = User::whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($current?->isAdmin(), 403);

        return $current;
    }

    private function context(int $itemId): array
    {
        if ($itemId < 1) {
            $this->fail('invalid');
        }
        $source = OrderDeliveryItem::find($itemId);
        if ($source) {
            $delivery = OrderDelivery::find($source->order_delivery_id);
            if ($delivery) {
                Order::withTrashed()->whereKey($delivery->order_id)->lockForUpdate()->first();
                OrderDelivery::whereKey($delivery->id)->lockForUpdate()->first();
            }
            OrderDeliveryItem::whereKey($itemId)->lockForUpdate()->first();
        }
        $record = ProcurementItem::where('order_delivery_item_id', $itemId)->lockForUpdate()->first();
        $item = $this->load($itemId);
        if (! $item && ! $record) {
            $this->fail('unavailable');
        }

        return [$item, $record, $this->snapshot($item, $record)];
    }

    private function load(int $itemId): ?OrderDeliveryItem
    {
        return OrderDeliveryItem::with(['delivery.order', 'supplier', 'product.translations'])->find($itemId);
    }

    private function snapshot(?OrderDeliveryItem $item, ?ProcurementItem $record): array
    {
        if (! $item || ! $item->delivery || ! $item->delivery->order) {
            $snapshot = $record?->source_snapshot ?: $record?->last_sent_snapshot ?: [];

            return [...$snapshot, 'item_id' => (int) $record?->order_delivery_item_id,
                'available' => false, 'source_reason' => 'deleted'];
        }
        $delivery = $item->delivery;
        $order = $delivery->order;
        // Apply the current private form's code below; reading its old persisted value would defeat clearing it.
        $identity = app(SupplierItemIdentity::class)->resolve($item, false);
        $reason = match (true) {
            $order->trashed() => 'deleted',
            ! $item->is_active => 'item_removed',
            $order->status === Order::CANCELLED || $delivery->status === OrderDelivery::CANCELLED => 'cancelled',
            $order->status !== Order::COMPLETED || ! $delivery->is_eligible => 'held',
            default => null,
        };
        $snapshot = ['item_id' => (int) $item->id, 'delivery_id' => (int) $delivery->id,
            'order_id' => (int) $order->id, 'order_number' => (string) $order->number,
            'delivery_date' => $delivery->delivery_date?->toDateString(),
            'supplier_id' => $item->supplier_id ? (int) $item->supplier_id : null,
            'supplier_code' => $item->supplier?->code, 'supplier_name' => $item->supplier?->name ?: $item->supplier_name,
            'product_id' => $item->product_id ? (int) $item->product_id : null, 'sku' => (string) $item->sku,
            'model' => (string) $item->name, 'reference' => trim((string) $item->supplier_sku) ?: null,
            'qty' => (int) $item->qty, 'native_sku' => $identity['native_sku'],
            'native_provenance' => $identity['provenance'], 'available' => $reason === null, 'source_reason' => $reason];
        if ($record?->native_sku && $record->native_identity_hash
            && hash_equals($record->native_identity_hash, $this->nativeIdentity($snapshot))) {
            $snapshot['native_sku'] = $record->native_sku;
            $snapshot['native_provenance'] = 'procurement_owner_confirmed';
        }

        return $snapshot;
    }

    private function nativeIdentity(array $snapshot): string
    {
        return $this->hash(array_intersect_key($snapshot, array_flip([
            'item_id', 'supplier_id', 'supplier_code', 'product_id', 'sku', 'model', 'reference',
        ])));
    }

    private function material(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(['item_id', 'delivery_id', 'order_id', 'order_number',
            'delivery_date', 'supplier_id', 'supplier_code', 'supplier_name', 'product_id', 'sku', 'model', 'reference', 'qty', 'native_sku']));
    }

    private function same(array $a, array $b): bool
    {
        return hash_equals($this->hash($this->material($a)), $this->hash($this->material($b)));
    }

    private function effectiveStatus(array $snapshot, ?ProcurementItem $record): string
    {
        if (! $snapshot['available']) {
            return 'issue';
        }
        if (! $record) {
            return 'unsent';
        }

        return $record->source_snapshot && $this->same($record->source_snapshot, $snapshot)
            ? $record->status : 'unsent';
    }

    private function rowHash(array $snapshot, ?ProcurementItem $record): string
    {
        return $this->hash([$snapshot, (int) $record?->version]);
    }

    private function currentRow(array $snapshot, ?ProcurementItem $record, ?OrderDeliveryItem $item = null, bool $hasPending = false): array
    {
        $row = $this->baseRow($snapshot);
        $row += ['key' => 'current:'.$snapshot['item_id'], 'type' => 'current',
            'expected_hash' => $this->rowHash($snapshot, $record), 'status' => $this->effectiveStatus($snapshot, $record),
            'note' => $record?->note, 'issue_qty' => $record?->issue_qty,
            'native_sku' => $snapshot['native_sku'] ?? null, 'available' => (bool) $snapshot['available'],
            'source_reason' => $snapshot['source_reason'],
            'handled' => $record && ! $snapshot['available'] && $record->last_sent_snapshot
                && ! $this->hasSent($record) && ! $hasPending && $record->status !== 'issue',
            'changed' => $record?->source_snapshot && (! $snapshot['available'] || ! $this->same($record->source_snapshot, $snapshot))];
        if ($item?->delivery?->order) {
            $row['orders'][0]['name'] = $item->delivery->order->name;
            $row['orders'][0]['phone'] = $item->delivery->order->phone;
        }
        $row['copy_text'] = $this->copyLine($row);

        return $row;
    }

    private function baseRow(array $snapshot): array
    {
        return ['item_id' => (int) ($snapshot['item_id'] ?? 0), 'supplier_id' => $snapshot['supplier_id'] ?? null,
            'supplier_code' => $snapshot['supplier_code'] ?? null, 'supplier_name' => $snapshot['supplier_name'] ?? null,
            'product_id' => $snapshot['product_id'] ?? null, 'sku' => $snapshot['sku'] ?? '',
            'model' => $snapshot['model'] ?? '', 'code' => $snapshot['native_sku'] ?? '',
            'code_label' => ! empty($snapshot['native_sku']) ? 'native' : 'none',
            'shop_sku' => $snapshot['sku'] ?? '', 'system_reference' => $snapshot['reference'] ?? null,
            'native_provenance' => $snapshot['native_provenance'] ?? null,
            'qty' => (int) ($snapshot['qty'] ?? 0), 'orders' => [['number' => $snapshot['order_number'] ?? '',
                'name' => '', 'phone' => '', 'date' => $snapshot['delivery_date'] ?? null]]];
    }

    private function hasSent(ProcurementItem $record): bool
    {
        return $record->last_sent_snapshot && (int) $record->sent_version > 0
            && (int) $record->withdrawn_sent_version !== (int) $record->sent_version;
    }

    private function needsWithdrawal(ProcurementItem $record, array $snapshot): bool
    {
        return $this->hasSent($record) && (! $snapshot['available'] || ! $this->same($record->last_sent_snapshot, $snapshot));
    }

    private function taskKey(ProcurementItem $record): string
    {
        return $this->hash([(int) $record->id, (int) $record->sent_version, $record->last_sent_snapshot]);
    }

    private function taskRowKey(int $itemId, string $taskKey): string
    {
        return 'task:'.$itemId.':'.$taskKey;
    }

    private function taskHash(string $taskKey, array $snapshot): string
    {
        return $this->hash([$taskKey, $snapshot, 'pending']);
    }

    private function taskRow(string $taskKey, array $snapshot): array
    {
        $row = $this->baseRow($snapshot);
        $row += ['key' => $this->taskRowKey($row['item_id'], $taskKey), 'type' => 'withdraw',
            'expected_hash' => $this->taskHash($taskKey, $snapshot), 'status' => 'withdraw', 'note' => null,
            'issue_qty' => null, 'available' => false, 'source_reason' => null, 'changed' => true];
        $row['copy_text'] = __('procurement.copy.withdraw').' '.$this->copyLine($row);

        return $row;
    }

    private function preserveWithdrawal(ProcurementItem $record, ?array $current): void
    {
        if (! $this->hasSent($record) || ($current && ! $this->needsWithdrawal($record, $current))) {
            return;
        }
        ProcurementTask::firstOrCreate(['task_key' => $this->taskKey($record)],
            ['procurement_item_id' => $record->id, 'snapshot' => $record->last_sent_snapshot]);
    }

    private function taskContext(string $rowKey, string $expectedHash): array
    {
        if (! preg_match('/\Atask:([1-9][0-9]*):([a-f0-9]{64})\z/D', $rowKey, $match)) {
            $this->fail('invalid');
        }
        [, $record, $current] = $this->context((int) $match[1]);
        if (! $record) {
            $this->fail('changed');
        }
        $task = ProcurementTask::where('task_key', $match[2])->where('procurement_item_id', $record->id)->lockForUpdate()->first();
        if ($task) {
            if ($task->acknowledged_at) {
                $this->fail('changed');
            }
            $snapshot = $task->snapshot;
        } elseif ($this->needsWithdrawal($record, $current) && hash_equals($this->taskKey($record), $match[2])) {
            $snapshot = $record->last_sent_snapshot;
        } else {
            $this->fail('changed');
        }
        $this->expected($expectedHash, $this->taskHash($match[2], $snapshot));

        return [$record, $match[2], $snapshot];
    }

    private function acknowledgeTask(ProcurementItem $record, string $taskKey, array $snapshot, User $actor): void
    {
        $task = ProcurementTask::firstOrCreate(['task_key' => $taskKey],
            ['procurement_item_id' => $record->id, 'snapshot' => $snapshot]);
        if ($task->acknowledged_at) {
            $this->fail('changed');
        }
        $task->fill(['acknowledged_at' => now(), 'acknowledged_by' => $actor->id])->save();
        if (hash_equals($this->taskKey($record), $taskKey)) {
            $record->withdrawn_sent_version = $record->sent_version;
            if ($record->status !== 'issue') {
                $record->status = 'unsent';
            }
            $record->source_snapshot = null;
        }
        $record->version++;
        $record->updated_by = $actor->id;
        $record->save();
        $this->event($record, 'withdrawal_acknowledged', $actor, ['task_key' => $taskKey, 'snapshot' => $snapshot]);
    }

    private function record(int $itemId, ?ProcurementItem $record, User $actor): ProcurementItem
    {
        return $record ?? ProcurementItem::create(['order_delivery_item_id' => $itemId, 'status' => 'unsent',
            'version' => 0, 'sent_version' => 0, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    private function filterSources(Builder $query, array $filters): Builder
    {
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.$this->like(trim($filters['q'])).'%';
            $query->where(fn (Builder $match) => $match->where('name', 'like', $needle)
                ->orWhere('sku', 'like', $needle)->orWhere('supplier_sku', 'like', $needle)
                ->orWhere('supplier_name', 'like', $needle)
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $needle))
                ->orWhereHas('delivery.order', fn (Builder $order) => $order->where('number', 'like', $needle)
                    ->orWhere('name', 'like', $needle)->orWhere('phone', 'like', $needle)));
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->whereHas('delivery', fn (Builder $delivery) => $delivery->whereDate('delivery_date', $operator, $filters[$key]));
            }
        }

        return $query;
    }

    private function filterSnapshot(Builder $query, string $column, array $filters): Builder
    {
        $query->whereNotNull($column);
        if (! empty($filters['supplier_id'])) {
            $query->where($column.'->supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.$this->like(trim($filters['q'])).'%';
            $query->where(function (Builder $match) use ($column, $needle) {
                foreach (['model', 'reference', 'native_sku', 'sku', 'order_number', 'supplier_name'] as $index => $field) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $match->{$method}($column.'->'.$field, 'like', $needle);
                }
            });
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where($column.'->delivery_date', $operator, $filters[$key]);
            }
        }

        return $query;
    }

    private function filterRecords(Builder $query, array $filters): Builder
    {
        return $query->where(function (Builder $match) use ($filters) {
            $match->whereHas('source', fn (Builder $source) => $this->filterSources($source, $filters))
                ->orWhere(fn (Builder $snapshot) => $this->filterSnapshot($snapshot, 'source_snapshot', $filters))
                ->orWhere(fn (Builder $snapshot) => $this->filterSnapshot($snapshot, 'last_sent_snapshot', $filters));
            if (! empty($filters['q'])) {
                $needle = '%'.$this->like(trim($filters['q'])).'%';
                $withoutSearch = array_diff_key($filters, ['q' => true]);
                $match->orWhere(function (Builder $private) use ($needle, $withoutSearch) {
                    $private->where(fn (Builder $text) => $text->where('note', 'like', $needle)->orWhere('native_sku', 'like', $needle))
                        ->where(function (Builder $scope) use ($withoutSearch) {
                            $scope->whereHas('source', fn (Builder $source) => $this->filterSources($source, $withoutSearch))
                                ->orWhere(fn (Builder $snapshot) => $this->filterSnapshot($snapshot, 'source_snapshot', $withoutSearch))
                                ->orWhere(fn (Builder $snapshot) => $this->filterSnapshot($snapshot, 'last_sent_snapshot', $withoutSearch));
                        });
                });
            }
        });
    }

    private function attentionRecords(Builder $query): Builder
    {
        return $query->whereNotNull('last_sent_snapshot')->where('sent_version', '>', 0)
            ->where(fn (Builder $sent) => $sent->whereNull('withdrawn_sent_version')->orWhereColumn('withdrawn_sent_version', '!=', 'sent_version'))
            ->where(function (Builder $changed) {
                $changed->whereDoesntHave('source', function (Builder $source) {
                    $source->where('is_active', true)->whereHas('delivery', function (Builder $delivery) {
                        $delivery->where('status', '!=', OrderDelivery::CANCELLED)->where('is_eligible', true)
                            ->whereHas('order', fn (Builder $order) => $order->withoutTrashed()->where('status', Order::COMPLETED));
                    });
                })->orWhereHas('source', function (Builder $source) {
                    $source->where(function (Builder $material) {
                        foreach (['supplier_id' => 'supplier_id', 'product_id' => 'product_id', 'sku' => 'sku',
                            'name' => 'model', 'supplier_sku' => 'reference', 'qty' => 'qty'] as $column => $key) {
                            $actual = 'order_delivery_items.'.$column;
                            $saved = 'procurement_items.last_sent_snapshot->'.$key;
                            $material->orWhere(function (Builder $different) use ($actual, $saved) {
                                $different->whereColumn($actual, '!=', $saved)
                                    ->orWhere(fn (Builder $null) => $null->whereNull($actual)->whereNotNull($saved))
                                    ->orWhere(fn (Builder $null) => $null->whereNotNull($actual)->whereNull($saved));
                            });
                        }
                        $material->orWhereHas('delivery', function (Builder $delivery) {
                            $grammar = $delivery->getQuery()->getGrammar();
                            $actual = $grammar->wrap('order_deliveries.delivery_date');
                            $saved = $grammar->wrap('procurement_items.last_sent_snapshot->delivery_date');
                            $delivery->whereRaw('DATE('.$actual.') <> DATE('.$saved.')');
                        });
                    });
                });
            });
    }

    private function filterRecordStatus(Builder $query, string $status): Builder
    {
        if (in_array($status, ['sent', 'confirmed', 'received', 'unsent'], true)) {
            $query->where('status', $status);
        } elseif ($status === 'issue') {
            $query->where(function (Builder $issue) {
                $issue->where('status', 'issue')->orWhereDoesntHave('source', function (Builder $source) {
                    $source->where('is_active', true)->whereHas('delivery', function (Builder $delivery) {
                        $delivery->where('status', '!=', OrderDelivery::CANCELLED)->where('is_eligible', true)
                            ->whereHas('order', fn (Builder $order) => $order->withoutTrashed()->where('status', Order::COMPLETED));
                    });
                });
            });
        } elseif ($status === 'withdraw') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function matches(array $row, array $filters): bool
    {
        $status = $filters['status'] ?? 'outstanding';
        if ($status === 'outstanding' && ($row['status'] === 'received' || ($row['handled'] ?? false))) {
            return false;
        }
        if (! in_array($status, ['all', 'outstanding'], true) && $row['status'] !== $status) {
            return false;
        }
        if (! empty($filters['supplier_id']) && (int) $row['supplier_id'] !== (int) $filters['supplier_id']) {
            return false;
        }
        $date = $row['orders'][0]['date'];
        if ((! empty($filters['date_from']) && (! $date || $date < $filters['date_from']))
            || (! empty($filters['date_to']) && (! $date || $date > $filters['date_to']))) {
            return false;
        }
        if (! empty($filters['q'])) {
            $haystack = implode(' ', [$row['model'], $row['code'], $row['sku'], $row['supplier_name'], $row['note'],
                ...array_merge(...array_map(fn (array $order) => array_values($order), $row['orders']))]);
            if (! str_contains(mb_strtolower($haystack), mb_strtolower(trim($filters['q'])))) {
                return false;
            }
        }

        return true;
    }

    private function copyLine(array $row): string
    {
        $model = $this->oneLine($row['model']);
        $code = $this->oneLine($row['code']);
        if ($code !== '') {
            $model .= ' | '.__('procurement.copy.'.$row['code_label']).': '.$code;
        }

        return $model.' | '.__('procurement.copy.quantity').': '.$row['qty'];
    }

    private function oneLine(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f]+|\s+/u', ' ', $value));
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function event(ProcurementItem $record, string $kind, User $actor, array $payload): void
    {
        ProcurementEvent::create(['procurement_item_id' => $record->id, 'kind' => $kind,
            'actor_id' => $actor->id, 'payload' => $payload]);
    }

    private function expected(string $expected, string $actual): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $expected) || ! hash_equals($actual, $expected)) {
            $this->fail('changed');
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['procurement' => __('procurement.errors.'.$key)]);
    }
}
