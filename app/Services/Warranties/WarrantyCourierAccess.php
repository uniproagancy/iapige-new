<?php

namespace App\Services\Warranties;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\User;
use App\Models\WarrantyCertificate;
use Illuminate\Database\Eloquent\Builder;

/** Current-delivery access; serial completion is separate from issued-document print/SMS access. */
class WarrantyCourierAccess
{
    public function delivery(User $actor, int $deliveryId, bool $lock = false): OrderDelivery
    {
        abort_unless($actor->isCourier(), 403);
        $query = OrderDelivery::query()->whereKey($deliveryId)->where('courier_id', $actor->id)
            ->whereIn('status', [OrderDelivery::ACTIVE, OrderDelivery::DELIVERED])
            ->where('is_eligible', true)
            ->whereHas('order', fn (Builder $order) => $order->withoutTrashed()->where('status', Order::COMPLETED));
        if ($lock) {
            $query->lockForUpdate();
        }

        $delivery = $query->firstOrFail();
        if ($lock) {
            $order = Order::withTrashed()->whereKey($delivery->order_id)->lockForUpdate()->first();
            abort_unless($order && ! $order->trashed() && $order->status === Order::COMPLETED, 404);
        }

        return $delivery;
    }

    public function certificates(OrderDelivery $delivery): Builder
    {
        return WarrantyCertificate::query()->where('order_id', $delivery->order_id)
            ->where('status', WarrantyCertificate::ISSUED)->whereNull('revoked_at')
            ->whereHas('deliveryItem', fn (Builder $item) => $item->where('order_delivery_id', $delivery->id)->where('is_active', true));
    }

    public function releasedDrafts(OrderDelivery $delivery): Builder
    {
        return WarrantyCertificate::query()->where('order_id', $delivery->order_id)
            ->where('status', WarrantyCertificate::DRAFT)->whereNull('revoked_at')
            ->whereHas('events', fn (Builder $event) => $event->where('type', 'certificate_courier_serial_released'))
            ->whereHas('deliveryItem', fn (Builder $item) => $item->where('order_delivery_id', $delivery->id)->where('is_active', true));
    }

    public function authorizeCertificate(WarrantyCertificate $certificate, User $actor, int $deliveryId, bool $lock = false): void
    {
        $delivery = $this->delivery($actor, $deliveryId, $lock);
        abort_unless($certificate->status === WarrantyCertificate::ISSUED && $certificate->revoked_at === null
            && (int) $certificate->order_id === (int) $delivery->order_id, 404);
        $items = OrderDeliveryItem::query()->whereKey($certificate->order_delivery_item_id)
            ->where('order_delivery_id', $delivery->id)->where('is_active', true);
        if ($lock) {
            $items->lockForUpdate();
        }
        abort_unless($items->first(['id']) !== null, 404);
    }
}
