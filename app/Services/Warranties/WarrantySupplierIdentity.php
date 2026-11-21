<?php

namespace App\Services\Warranties;

use App\Models\OrderDeliveryItem;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\WarrantyCertificate;

/** Distinguishes a supplier's public article code from the import system's product identifier. */
class WarrantySupplierIdentity
{
    /** Owner-confirmed warranty display identity; the legacy import code remains unchanged. */
    public static function isSkytech(int $supplierId, ?string $supplierCode): bool
    {
        return $supplierId === 12 && $supplierCode === 'citrus';
    }

    public static function displayName(?Supplier $supplier): ?string
    {
        if ($supplier && (int) $supplier->id === 9 && $supplier->code === 'metromart') {
            return 'შპს „შამი“';
        }
        return $supplier && self::isSkytech((int) $supplier->id, $supplier->code)
            ? 'შპს გოლდენ SKYTECH' : $supplier?->name;
    }

    public function resolve(?OrderDeliveryItem $item, ?WarrantyCertificate $certificate = null): array
    {
        $result = ['native_sku' => null, 'system_reference' => filled($item?->supplier_sku) ? trim($item->supplier_sku) : null,
            'source' => null, 'reference' => null, 'identity_hash' => null, 'confirmed' => false];
        if (! $item || ! ($hash = $this->identityHash($item))) {
            return $result;
        }
        $result['identity_hash'] = $hash;
        if ($certificate && $certificate->supplier_native_sku_source === 'staff_confirmed') {
            $item->loadMissing('delivery');
        }
        if ($certificate && $item->exists && $certificate->order_delivery_item_id && $item->delivery?->order_id
            && (int) $certificate->supplier_id === (int) $item->supplier_id
            && (int) $certificate->product_id === (int) $item->product_id
            && (int) $certificate->order_delivery_item_id === (int) $item->id
            && (int) $certificate->order_id === (int) $item->delivery?->order_id
            && $certificate->supplier_native_sku_source === 'staff_confirmed'
            && is_string($certificate->supplier_native_sku) && trim($certificate->supplier_native_sku) !== ''
            && mb_strlen($certificate->supplier_native_sku) <= 191
            && ! preg_match('/[\x00-\x1f\x7f]/u', $certificate->supplier_native_sku)
            && is_string($certificate->supplier_native_sku_identity_sha256)
            && hash_equals($hash, $certificate->supplier_native_sku_identity_sha256)) {
            return [...$result, 'native_sku' => $certificate->supplier_native_sku, 'source' => 'staff_confirmed',
                'reference' => $certificate->supplier_native_sku_reference, 'confirmed' => true];
        }
        if (! $item->product_id) {
            return $result;
        }
        $item->loadMissing('product');
        if (! $item->product) {
            return $result;
        }
        $matches = [];
        foreach ($this->identities() as $identity) {
            if ((int) ($identity['supplier_id'] ?? 0) !== (int) $item->supplier_id
                || ($identity['supplier_code'] ?? null) !== $item->supplier->code
                || (int) ($identity['product_id'] ?? 0) !== (int) $item->product_id
                || ($identity['system_reference'] ?? null) !== $result['system_reference']
                || ($identity['expected_product_sku'] ?? null) !== $item->product->sku
                || ($identity['expected_product_sku'] ?? null) !== $item->sku
                || $this->name($identity['expected_name'] ?? '') !== $this->name((string) $item->product->name)
                || $this->name($identity['expected_name'] ?? '') !== $this->name((string) $item->name)
                || ! is_string($identity['native_sku'] ?? null) || trim($identity['native_sku']) === ''
                || ! filled($identity['reference'] ?? null)) {
                continue;
            }
            $actual = ProductOffer::where('product_id', $item->product_id)->where('supplier_id', $item->supplier_id)
                ->pluck('external_id')->map(fn ($value) => trim((string) $value))->unique()->sort()->values()->all();
            $expected = array_values(array_unique(array_map('strval', $identity['expected_offer_references'] ?? [])));
            sort($expected, SORT_STRING);
            if ($actual !== [] && $actual === $expected) {
                $matches[] = $identity;
            }
        }
        if (count($matches) !== 1) {
            return $result;
        }
        return [...$result, 'native_sku' => $matches[0]['native_sku'], 'source' => 'verified_registry',
            'reference' => $matches[0]['reference'], 'confirmed' => true];
    }

    public function identityHash(OrderDeliveryItem $item): ?string
    {
        if (! $item->supplier_id) {
            return null;
        }
        $item->loadMissing('supplier');
        if (! $item->supplier || (int) $item->supplier->id !== (int) $item->supplier_id) {
            return null;
        }
        return hash('sha256', json_encode([(int) $item->supplier_id, $item->supplier->code,
            $item->product_id ? (int) $item->product_id : null, $item->sku, $item->name, $item->supplier_sku],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function identities(): array
    {
        $file = resource_path('warranty/supplier-native-identities.json');
        try {
            $data = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        return ($data['schema'] ?? null) === 'iapi.warranty.supplier-native-identities.v1'
            && is_array($data['identities'] ?? null) ? $data['identities'] : [];
    }

    private function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }
}
