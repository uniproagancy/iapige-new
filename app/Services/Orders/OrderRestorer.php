<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use App\Services\Delivery\OrderDeliveryManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OrderRestorer
{
    public function restore(int $orderId, User $actor): Order
    {
        $actor = User::find($actor->id);

        if (! $actor?->isStaff()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($orderId, $actor) {
            // Use the canonical lock order shared by delivery actions.
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::CANCELLED) {
                return $order;
            }

            $delivery = app(OrderDeliveryManager::class)->isInstalled()
                ? OrderDelivery::where('order_id', $order->id)->lockForUpdate()->first()
                : null;

            $order->update(['status' => Order::VIEWED]);
            $order->events()->create([
                'user_id' => $actor->id,
                'type' => 'status',
                'from' => Order::CANCELLED,
                'to' => Order::VIEWED,
                'note' => __('order_restore.event'),
            ]);

            if ($delivery?->status === OrderDelivery::CANCELLED) {
                // Keep actual pickup, handover and cash facts intact. An
                // undelivered order is held until the operator completes it.
                $status = $delivery->delivered_at !== null
                    ? OrderDelivery::DELIVERED : OrderDelivery::ACTIVE;
                $delivery->update(['status' => $status, 'is_eligible' => false]);
                $order->events()->create([
                    'user_id' => $actor->id,
                    'type' => 'delivery_restored',
                    'from' => OrderDelivery::CANCELLED,
                    'to' => $status,
                    'note' => __('order_restore.delivery_event'),
                ]);
            }

            return $order;
        }, 3);
    }
}
