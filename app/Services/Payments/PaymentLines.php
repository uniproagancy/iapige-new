<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderItem;

/*
 | The basket a bank is shown.
 |
 | Banks are sent the order line by line, and delivery is not a product: TBC
 | added the lines up for its total and so never charged the delivery, and
 | BOG, asked to check the lines against the amount, found them short by it.
 | The delivery goes onto one product, once -- never onto every unit of a line
 | of two, and never spread over several products.
 */
class PaymentLines
{
    /**
     * @return array<int, array{item: OrderItem, price: float, qty: int}>
     */
    public function of(Order $order): array
    {
        $lines = $order->items
            ->map(fn (OrderItem $item) => ['item' => $item, 'price' => (float) $item->price, 'qty' => (int) $item->qty])
            ->values()
            ->all();

        if (! $lines) {
            return [];
        }

        /* Whatever the order costs beyond its products -- the delivery -- so
           the lines always come to exactly what is charged. */
        $extra = round((float) $order->total - array_sum(array_map(fn (array $line) => $line['price'] * $line['qty'], $lines)), 2);

        if (abs($extra) < 0.005) {
            return $lines;
        }

        $at = $this->carrier($lines);

        if ($lines[$at]['price'] + $extra <= 0) {
            return $lines;
        }

        if ($lines[$at]['qty'] === 1) {
            $lines[$at]['price'] = round($lines[$at]['price'] + $extra, 2);

            return $lines;
        }

        /* Every line holds two or more: one unit is set apart to carry it. */
        $lines[$at]['qty']--;

        array_splice($lines, $at + 1, 0, [[
            'item' => $lines[$at]['item'],
            'price' => round($lines[$at]['price'] + $extra, 2),
            'qty' => 1,
        ]]);

        return $lines;
    }

    /* The dearest single unit carries it, so no line has to be split; failing
       that, the dearest line. */
    protected function carrier(array $lines): int
    {
        $singles = array_filter($lines, fn (array $line) => $line['qty'] === 1);
        $pool = $singles ?: $lines;

        uasort($pool, fn (array $a, array $b) => $b['price'] <=> $a['price']);

        return (int) array_key_first($pool);
    }
}
