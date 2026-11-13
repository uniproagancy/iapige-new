<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliveryAccounting
{
    public function data(string $from, string $to, User $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException;
        }
        $this->validatePeriod($from, $to);

        return DB::transaction(function () use ($from, $to, $actor) {
            $deliveries = OrderDelivery::query()->visibleTo($actor)
                ->whereDate('delivery_date', '>=', $from)->whereDate('delivery_date', '<=', $to)
                ->with(['order', 'items'])->orderBy('delivery_date')->orderBy('id')->get();
            $orders = collect();
            $lines = collect();
            $days = [];
            $totals = $this->emptyTotals();
            foreach ($deliveries as $delivery) {
                $date = $delivery->delivery_date->toDateString();
                $state = $delivery->status === OrderDelivery::CANCELLED || $delivery->order->status === Order::CANCELLED
                    ? 'cancelled' : ($delivery->isHeld() ? 'held' : $delivery->status);
                $order = [
                    'delivery_id' => $delivery->id, 'order_id' => $delivery->order_id,
                    'order_number' => $delivery->order->number, 'delivery_date' => $date, 'status' => $state,
                    'customer_name' => $delivery->name, 'phone' => $delivery->phone, 'address' => $delivery->address,
                    'payment' => $delivery->payment, 'shipping_cents' => $this->cents($delivery->shipping),
                    'total_cents' => $this->cents($delivery->total), 'product_revenue_cents' => $this->cents($delivery->subtotal),
                    'lines' => collect(),
                ];
                foreach ($delivery->items as $item) {
                    $line = [
                        'item_id' => $item->id, 'delivery_id' => $delivery->id, 'order_id' => $delivery->order_id,
                        'order_number' => $order['order_number'], 'delivery_date' => $date, 'status' => $state,
                        'customer_name' => $delivery->name, 'phone' => $delivery->phone,
                        'supplier_name' => $item->supplier_name, 'sku' => $item->sku, 'name' => $item->name,
                        'qty' => $item->qty, 'unit_sale_cents' => $this->cents($item->price), 'sale_cents' => $this->cents($item->sum),
                    ];
                    $order['lines']->push($line);
                    $lines->push($line);
                }
                $orders->push($order);
                $days[$date] ??= $this->emptyTotals();
                $this->addOrder($totals, $order);
                $this->addOrder($days[$date], $order);
            }
            ksort($days);

            return compact('from', 'to', 'orders', 'lines', 'totals', 'days');
        });
    }

    public function validatePeriod(string $from, string $to): void
    {
        Validator::make(compact('from', 'to'), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 3660) {
            throw ValidationException::withMessages(['to' => __('accounting.errors.range_too_large')]);
        }
    }

    protected function addOrder(array &$totals, array $order): void
    {
        $totals['orders']++;
        $key = ['active' => 'ready_orders', 'delivered' => 'delivered_orders', 'held' => 'held_orders', 'cancelled' => 'cancelled_orders'][$order['status']] ?? null;
        if ($key !== null) {
            $totals[$key]++;
        }
        if (in_array($order['status'], ['active', 'delivered'], true)) {
            $totals['planned_revenue_cents'] += $order['total_cents'];
        }
        if ($order['status'] === 'delivered') {
            $totals['realized_revenue_cents'] += $order['total_cents'];
        }
    }

    protected function emptyTotals(): array
    {
        return array_fill_keys(['orders', 'ready_orders', 'delivered_orders', 'held_orders', 'cancelled_orders',
            'planned_revenue_cents', 'realized_revenue_cents'], 0);
    }

    protected function cents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }
}
