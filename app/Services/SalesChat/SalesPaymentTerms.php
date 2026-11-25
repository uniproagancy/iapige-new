<?php

namespace App\Services\SalesChat;

use App\Models\PaymentMethod;
use App\Services\Orders\QuickOrder;

class SalesPaymentTerms
{
    public function __construct(private readonly QuickOrder $quickOrder) {}

    /** @return list<array{name:string,min_total?:float,max_total?:float}> */
    public function installments(?float $orderTotal = null): array
    {
        if ($orderTotal !== null && (! is_finite($orderTotal) || $orderTotal <= 0)) {
            return [];
        }

        return PaymentMethod::active()->withTranslation()->where('is_online', true)->get()
            ->filter(fn (PaymentMethod $method) => $this->quickOrder->category($method) === 'installment'
                && $this->quickOrder->runnable($method)
                && ($orderTotal === null || $method->fitsTotal($orderTotal)))
            ->map(fn (PaymentMethod $method) => $this->terms($method))
            ->filter()->take(8)->values()->all();
    }

    private function terms(PaymentMethod $method): ?array
    {
        $name = mb_substr(trim(strip_tags((string) $method->name)), 0, 100);
        $minimum = $method->min_total !== null ? (float) $method->min_total : null;
        $maximum = $method->max_total !== null ? (float) $method->max_total : null;

        if ($name === '' || ($maximum !== null && ($maximum <= 0 || ($minimum !== null && $minimum > $maximum)))) {
            return null;
        }

        return [
            'name' => $name,
            ...($minimum > 0 ? ['min_total' => $minimum] : []),
            ...($maximum !== null ? ['max_total' => $maximum] : []),
        ];
    }
}
