<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Delivery\OrderDeliveryManager;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class OrderFulfillmentObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Order|OrderItem $model): void
    {
        $this->sync($model);
    }

    public function deleted(Order|OrderItem $model): void
    {
        $this->sync($model);
    }

    public function restored(Order $model): void
    {
        $this->sync($model);
    }

    protected function sync(Order|OrderItem $model): void
    {
        $manager = app(OrderDeliveryManager::class);

        // Code can be installed just before its migration while checkout remains open.
        if ($manager->isInstalled()) {
            $manager->sync($model instanceof Order ? $model->id : $model->order_id);
        }
    }
}
