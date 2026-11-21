<?php

namespace App\Services\Delivery;

use App\Models\OrderDeliveryItem;
use App\Models\ProductOffer;
use App\Models\ProcurementItem;
use App\Models\SupplierStock;
use App\Models\WarrantyCertificate;
use App\Services\Warranties\WarrantySupplierIdentity;
use Illuminate\Support\Facades\Schema;

/** A display-only separation of shop SKU, import lookup key and evidenced supplier article. */
class SupplierItemIdentity
{
    private ?array $verifiedIdentities = null;

    private ?bool $hasWarrantyCodes = null;

    private ?bool $hasOwnerCodes = null;

    public function resolve(OrderDeliveryItem $item, bool $includeStoredManual = true): array
    {
        $result = ['shop_sku' => trim((string) $item->sku),
            'system_reference' => $this->code($item->supplier_sku), 'native_sku' => null, 'provenance' => null];
        if (! $item->exists || ! $item->supplier_id) {
            return $result;
        }
        $item->loadMissing(['supplier', 'delivery']);
        if (! $item->supplier || (int) $item->supplier->id !== (int) $item->supplier_id) {
            return $result;
        }
        $this->hasOwnerCodes ??= Schema::hasTable('procurement_items')
            && Schema::hasColumns('procurement_items', ['native_sku', 'native_identity_hash']);
        if ($includeStoredManual && $this->hasOwnerCodes) {
            // Read only the explicit article and its binding; private notes/progress never enter delivery views.
            $ownerCode = ProcurementItem::where('order_delivery_item_id', $item->id)
                ->first(['native_sku', 'native_identity_hash']);
            if ($ownerCode && ($native = $this->code($ownerCode->native_sku))
                && is_string($ownerCode->native_identity_hash)
                && preg_match('/\A[a-f0-9]{64}\z/D', $ownerCode->native_identity_hash)
                && hash_equals($ownerCode->native_identity_hash, $this->contextHash($item))) {
                return [...$result, 'native_sku' => $native, 'provenance' => 'shared_manual'];
            }
        }
        $warranty = app(WarrantySupplierIdentity::class);
        $identityHash = $warranty->identityHash($item);
        $this->hasWarrantyCodes ??= Schema::hasTable('warranty_certificates')
            && Schema::hasColumns('warranty_certificates', ['supplier_native_sku', 'supplier_native_sku_identity_sha256']);
        if ($this->hasWarrantyCodes && $identityHash && $item->delivery?->order_id) {
            // Pick current revisions before checking proof. Cleared/revoked corrections cannot borrow an older code.
            $latestUnits = WarrantyCertificate::where('order_delivery_item_id', $item->id)
                ->whereNotExists(function ($newer) {
                    $newer->selectRaw('1')->from('warranty_certificates as newer')
                        ->whereColumn('newer.order_delivery_item_id', 'warranty_certificates.order_delivery_item_id')
                        ->whereColumn('newer.unit_index', 'warranty_certificates.unit_index')
                        ->where(function ($revision) {
                            $revision->whereColumn('newer.revision', '>', 'warranty_certificates.revision')
                                ->orWhere(function ($same) {
                                    $same->whereColumn('newer.revision', 'warranty_certificates.revision')
                                        ->whereColumn('newer.id', '>', 'warranty_certificates.id');
                                });
                        });
                })->orderBy('unit_index')->limit(101)->get(['id', 'order_delivery_item_id', 'order_id', 'supplier_id',
                    'product_id', 'unit_index', 'revision', 'status', 'supplier_native_sku_source',
                    'supplier_native_sku', 'supplier_native_sku_reference', 'supplier_native_sku_identity_sha256']);
            $nativeCodes = [];
            $consistent = $latestUnits->isNotEmpty() && $latestUnits->count() <= 100;
            foreach ($latestUnits as $certificate) {
                $native = $this->code($certificate->supplier_native_sku);
                if (! $native || ! in_array($certificate->status, [WarrantyCertificate::DRAFT, WarrantyCertificate::ISSUED], true)
                    || $certificate->supplier_native_sku_source !== 'staff_confirmed'
                    || (int) $certificate->order_id !== (int) $item->delivery->order_id
                    || (int) $certificate->supplier_id !== (int) $item->supplier_id
                    || (int) $certificate->product_id !== (int) $item->product_id
                    || (int) $certificate->unit_index < 1 || (int) $certificate->unit_index > (int) $item->qty
                    || ! is_string($certificate->supplier_native_sku_identity_sha256)
                    || ! hash_equals($identityHash, $certificate->supplier_native_sku_identity_sha256)) {
                    $consistent = false;
                    break;
                }
                $manual = $warranty->resolve($item, $certificate);
                if (($manual['source'] ?? null) !== 'staff_confirmed'
                    || $this->code($manual['native_sku'] ?? null) !== $native) {
                    $consistent = false;
                    break;
                }
                $nativeCodes[$native] = true;
            }
            if ($consistent && count($nativeCodes) === 1) {
                return [...$result, 'native_sku' => (string) array_key_first($nativeCodes), 'provenance' => 'warranty_staff_confirmed'];
            }
        }
        // Reuse only exact verified registry evidence; issued warranty snapshots are never changed.
        $known = $warranty->resolve($item);
        if ($native = $this->code($known['native_sku'] ?? null)) {
            return [...$result, 'native_sku' => $native, 'provenance' => 'warranty_verified_registry'];
        }
        $item->loadMissing('product.translations');
        if (! $item->product || (int) $item->product->id !== (int) $item->product_id
            || (string) $item->product->sku !== (string) $item->sku) {
            return $result;
        }
        $references = ProductOffer::where('supplier_id', $item->supplier_id)->where('product_id', $item->product_id)
            ->orderBy('external_id')->limit(21)->pluck('external_id')->map(fn ($value) => $this->code($value))
            ->filter()->unique()->values()->all();
        if ($references === [] || count($references) > 20) {
            return $result;
        }
        $verified = $this->verified($item, $references, $result['system_reference']);
        if ($verified) {
            return [...$result, 'native_sku' => $verified, 'provenance' => 'delivery_verified_registry'];
        }
        if (count($references) !== 1 || $result['system_reference'] !== $references[0]) {
            return $result;
        }
        $supplier = $item->supplier;
        // Allmarket's API explicitly names this field productCode; it has no supplier_stocks mirror.
        if ((int) $supplier->id === 5 && $supplier->code === 'allmarket'
            && $supplier->driver === 'App\\Services\\Import\\Drivers\\Allmarket\\AllmarketDriver') {
            return [...$result, 'native_sku' => $references[0], 'provenance' => 'allmarket_api_product_code'];
        }
        if (! Schema::hasTable('supplier_stocks')) {
            return $result;
        }
        $stock = SupplierStock::where('supplier_id', $item->supplier_id)->where('external_id', $references[0])->first();
        if (! $stock || ! is_array($stock->data)) {
            return $result;
        }
        // These are explicit supplier fields, not a guess obtained by removing our SKU prefix.
        if ((int) $supplier->id === 2 && $supplier->code === 'alta'
            && $supplier->driver === 'App\\Services\\Import\\Drivers\\Alta\\AltaDriver'
            && ($native = $this->code($stock->data['item'] ?? null)) === $references[0]) {
            return [...$result, 'native_sku' => $native, 'provenance' => 'alta_b2b_item'];
        }
        if ((int) $supplier->id === 14 && $supplier->code === 'magitoys'
            && $supplier->driver === 'App\\Services\\Import\\Drivers\\MagiToys\\MagiToysDriver'
            && filled($supplier->config['columns']['code'] ?? null)
            && ($native = $this->code($stock->data['code'] ?? null))
            && (string) $item->product->sku === strtoupper($supplier->code).'-'.$native) {
            return [...$result, 'native_sku' => $native, 'provenance' => 'magitoys_sheet_code'];
        }
        if ((int) $supplier->id === 4 && $supplier->code === 'midea'
            && $supplier->driver === 'App\\Services\\Import\\Drivers\\Midea\\MideaDriver'
            && ($supplier->config['columns']['key'] ?? null) === 'C'
            && ($model = $this->code($stock->data['model'] ?? null))
            && ($this->containsModel((string) $item->product->name, $model)
                || $this->containsModel((string) $item->name, $model))) {
            return [...$result, 'native_sku' => $references[0], 'provenance' => 'midea_sheet_barcode'];
        }
        $sheetCodes = [
            6 => ['ingco', 'Ingco', 'ingco_sheet_model'],
            7 => ['alneo', 'Alneo', 'alneo_sheet_article'],
            9 => ['metromart', 'Metromart', 'metromart_sheet_model'],
            13 => ['elitlight', 'Elitlight', 'elitlight_sheet_article'],
        ];
        if (isset($sheetCodes[(int) $supplier->id])) {
            [$code, $driver, $provenance] = $sheetCodes[(int) $supplier->id];
            if ($supplier->code === $code
                && $supplier->driver === 'App\\Services\\Import\\Drivers\\'.$driver.'\\'.$driver.'Driver'
                && ($supplier->config['columns']['key'] ?? null) === 'A'
                && ($supplier->config['columns']['key_as'] ?? null) !== 'url'
                && empty($supplier->config['columns']['key_else'])) {
                return [...$result, 'native_sku' => $references[0], 'provenance' => $provenance];
            }
        }

        return $result;
    }

    /** Compatible with the existing procurement manual-code binding, without reading private state. */
    public function contextHash(OrderDeliveryItem $item): string
    {
        $item->loadMissing('supplier');

        return hash('sha256', json_encode(['item_id' => (int) $item->id,
            'supplier_id' => $item->supplier_id ? (int) $item->supplier_id : null,
            'supplier_code' => $item->supplier?->code, 'product_id' => $item->product_id ? (int) $item->product_id : null,
            'sku' => (string) $item->sku, 'model' => (string) $item->name,
            'reference' => trim((string) $item->supplier_sku) ?: null],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function verified(OrderDeliveryItem $item, array $actualReferences, ?string $reference): ?string
    {
        $matches = [];
        foreach ($this->identities() as $identity) {
            if (! is_array($identity) || ($identity['supplier_id'] ?? null) !== (int) $item->supplier_id
                || ($identity['supplier_code'] ?? null) !== $item->supplier->code
                || ($identity['product_id'] ?? null) !== (int) $item->product_id
                || ($identity['system_reference'] ?? null) !== $reference
                || ($identity['expected_product_sku'] ?? null) !== (string) $item->sku
                || ($identity['expected_product_sku'] ?? null) !== (string) $item->product->sku
                || ! is_string($identity['expected_name'] ?? null)
                || $this->name($identity['expected_name']) !== $this->name((string) $item->name)
                || $this->name($identity['expected_name']) !== $this->name((string) $item->product->name)
                || ! is_string($identity['reference'] ?? null) || trim($identity['reference']) === ''
                || ! is_string($identity['proof_sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $identity['proof_sha256'])
                || ! is_array($identity['expected_offer_references'] ?? null)
                || count($identity['expected_offer_references']) > 20
                || ! ($native = $this->code($identity['native_sku'] ?? null))) {
                continue;
            }
            $expected = array_values(array_unique(array_filter(array_map(fn ($value) => $this->code($value), $identity['expected_offer_references']))));
            sort($expected, SORT_STRING);
            sort($actualReferences, SORT_STRING);
            if ($expected !== [] && $expected === $actualReferences) {
                $matches[] = $native;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function identities(): array
    {
        if ($this->verifiedIdentities !== null) {
            return $this->verifiedIdentities;
        }
        $path = resource_path('delivery/supplier-native-identities.json');
        if (! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return $this->verifiedIdentities = [];
        }
        try {
            $data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->verifiedIdentities = [];
        }

        return $this->verifiedIdentities = ($data['schema'] ?? null) === 'iapi.delivery.supplier-native-identities.v1'
            && is_array($data['identities'] ?? null) && count($data['identities']) <= 2000 ? $data['identities'] : [];
    }

    private function code(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= 191 && ! preg_match('/[\x00-\x1f\x7f]/u', $value) ? $value : null;
    }

    private function name(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function containsModel(string $name, string $model): bool
    {
        // Exact supplier model tokens may differ only in separators, as the existing Midea driver permits.
        $model = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $model));
        $name = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name));

        return $model !== '' && str_contains($name, $model);
    }
}
