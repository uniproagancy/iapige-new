<?php

namespace App\Services\Delivery;

use App\Models\DeliveryCity;

class DeliveryCalculator
{
    public const VOLUMETRIC_DIVISOR = 5000;

    public function fee(DeliveryCity $city, array $items): float
    {
        foreach ($items as $item) {
            if (($item['is_bulky'] ?? false) && $city->bulky_fee !== null) {
                return (float) $city->bulky_fee;
            }
        }

        $grams = $this->billableWeight($items);

        $tier = $city->rates()
            ->where('up_to_weight', '>=', $grams)
            ->orderBy('up_to_weight')
            ->first();

        if ($tier) {
            return (float) $tier->fee;
        }

        $last = $city->rates()->orderByDesc('up_to_weight')->first();

        if (! $last) {
            return (float) $city->fee;
        }

        $extraKg = ceil(max(0, $grams - $last->up_to_weight) / 1000);

        return (float) $last->fee + $extraKg * (float) ($city->per_kg_over ?? 0);
    }

    public function billableWeight(array $items): int
    {
        $real = 0;
        $volumetric = 0;

        foreach ($items as $item) {
            $qty = max(1, $item['qty'] ?? 1);
            $real += (int) ($item['weight'] ?? 0) * $qty;

            if (! empty($item['length']) && ! empty($item['width']) && ! empty($item['height'])) {
                $cm3 = ($item['length'] / 10) * ($item['width'] / 10) * ($item['height'] / 10);
                $volumetric += (int) round($cm3 / self::VOLUMETRIC_DIVISOR * 1000) * $qty;
            }
        }

        return max($real, $volumetric);
    }
}
