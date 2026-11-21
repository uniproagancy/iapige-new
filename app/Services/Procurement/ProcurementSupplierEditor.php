<?php

namespace App\Services\Procurement;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\OrderDeliveryManager;

/** The sole shared write is the existing supplier override, never delivery/payment synchronization. */
class ProcurementSupplierEditor extends OrderDeliveryManager
{
    public function apply(OrderDeliveryItem $item, int $supplierId, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
        abort_unless(\Illuminate\Support\Facades\DB::transactionLevel() > 0, 409);
        $delivery = $item->delivery;
        $order = $delivery?->order;
        abort_unless($delivery && $order && ! $order->trashed() && $item->is_active
            && $delivery->status === OrderDelivery::ACTIVE && $delivery->is_eligible
            && $order->status === Order::COMPLETED, 409);
        Supplier::whereKey($supplierId)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $this->updateItems($delivery, [$item->id => ['supplier_id' => $supplierId]], $order, $actor);
    }
}
