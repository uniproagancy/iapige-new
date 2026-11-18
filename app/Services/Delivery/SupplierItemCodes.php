<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\ProcurementEvent;
use App\Models\ProcurementItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit code editing does not change the order, offer/import IDs or personal procurement progress. */
class SupplierItemCodes
{
    public function editHash(OrderDeliveryItem $item): string
    {
        $record = ProcurementItem::where('order_delivery_item_id', $item->id)
            ->first(['version', 'native_sku', 'native_identity_hash']);

        return $this->fingerprint(app(SupplierItemIdentity::class)->contextHash($item), $record);
    }

    public function save(int $itemId, ?string $code, string $expectedHash, User $actor): void
    {
        Validator::make(['code' => $code, 'identity' => $expectedHash], [
            'code' => ['nullable', 'string', 'max:191', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'identity' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
        ])->validate();

        DB::transaction(function () use ($itemId, $code, $expectedHash, $actor): void {
            $currentActor = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($currentActor?->isStaff(), 403);
            $reference = OrderDeliveryItem::findOrFail($itemId);
            $deliveryReference = OrderDelivery::findOrFail($reference->order_delivery_id);
            $order = Order::withTrashed()->whereKey($deliveryReference->order_id)->lockForUpdate()->firstOrFail();
            $delivery = OrderDelivery::whereKey($deliveryReference->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $item = OrderDeliveryItem::with(['supplier', 'product'])->whereKey($itemId)
                ->where('order_delivery_id', $delivery->id)->lockForUpdate()->firstOrFail();
            if ($order->trashed() || $order->status === Order::CANCELLED || $delivery->status !== OrderDelivery::ACTIVE
                || ! $item->is_active || ! $item->supplier_id || ! $item->supplier) {
                throw ValidationException::withMessages(['nativeSkuForms.'.$itemId => __('delivery.native_code_unavailable')]);
            }
            $hash = app(SupplierItemIdentity::class)->contextHash($item);
            $record = ProcurementItem::where('order_delivery_item_id', $itemId)->lockForUpdate()->first();
            if (! hash_equals($this->fingerprint($hash, $record), $expectedHash)) {
                throw ValidationException::withMessages(['nativeSkuForms.'.$itemId => __('delivery.native_code_changed')]);
            }
            $value = trim((string) $code) ?: null;
            if (! $record && $value === null) {
                return;
            }
            $record ??= new ProcurementItem(['order_delivery_item_id' => $itemId, 'status' => 'unsent',
                'version' => 0, 'sent_version' => 0, 'created_by' => $currentActor->id]);
            $before = $record->only(['native_sku', 'native_identity_hash']);
            $record->native_sku = $value;
            $record->native_identity_hash = $value === null ? null : $hash;
            if (! $record->isDirty()) {
                return;
            }
            $record->version++;
            $record->updated_by = $currentActor->id;
            $record->save();
            ProcurementEvent::create(['procurement_item_id' => $record->id, 'kind' => 'native_sku_updated',
                'actor_id' => $currentActor->id, 'payload' => ['before' => $before,
                    'after' => $record->only(['native_sku', 'native_identity_hash'])], 'created_at' => now()]);
        }, 3);
    }

    private function fingerprint(string $identity, ?ProcurementItem $record): string
    {
        return hash('sha256', json_encode([$identity, (int) $record?->version,
            $record?->native_sku, $record?->native_identity_hash], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
