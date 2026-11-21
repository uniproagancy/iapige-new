<?php

namespace App\Services\Warranties;

use App\Models\OrderDeliveryItem;
use App\Models\WarrantyCertificate;
use Illuminate\Validation\ValidationException;

/** Trusted supplier dealer text; it does not approve a policy, choose a period or waive an original. */
class WarrantyDealerForm
{
    private const SOURCE_SHA256 = '57a634541997167732b1c057341be460a242dc2d4113761f474f04f613b6ce2a';
    private const RESOURCE_SHA256 = '89917cbf4c30e8c0890dd235c29a3275449a3687cb14533fdfa0159a9162d2bf';

    public function forSupplier(int $supplierId, string $supplierCode): ?array
    {
        if ($supplierId === 1 && $supplierCode === 'zoommer') {
            return app(WarrantyZoommerDealerForm::class)->forSupplier($supplierId, $supplierCode);
        }
        if ($supplierId !== 3 || $supplierCode !== 'elite') { return null; }
        $path = resource_path('warranty/elite-dealer-2024.json');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 256 * 1024) { $this->invalid(); }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals(self::RESOURCE_SHA256, hash('sha256', $bytes))) { $this->invalid(); }
        try { $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $this->invalid(); }
        return $this->frozen($data);
    }

    public function forCertificate(WarrantyCertificate $certificate): ?array
    {
        // Historical issued objects can only reuse their own frozen block, never the current resource.
        if (in_array($certificate->getRawOriginal('status'), [WarrantyCertificate::ISSUED, WarrantyCertificate::REVOKED], true)) {
            return $this->frozen($certificate->snapshot['supplier_dealer_form'] ?? null);
        }
        if ((int) $certificate->supplier_id === 1) {
            return app(WarrantyZoommerDealerForm::class)->forCertificate($certificate);
        }
        $item = $certificate->order_delivery_item_id
            ? OrderDeliveryItem::with(['supplier', 'delivery'])->find($certificate->order_delivery_item_id) : null;
        if (! $item || ! $item->is_active || (int) $item->supplier_id !== 3 || $item->supplier?->code !== 'elite'
            || (int) $certificate->supplier_id !== (int) $item->supplier_id
            || (int) $certificate->product_id !== (int) $item->product_id
            || (int) $certificate->order_id !== (int) $item->delivery?->order_id
            || (int) $certificate->order_delivery_item_id !== (int) $item->id) { return null; }
        $proof = $certificate->supplier_native_sku_identity_sha256;
        $identity = filled($proof) ? app(WarrantySupplierIdentity::class)->identityHash($item) : null;
        if (filled($proof) && (! $identity || ! hash_equals($proof, $identity))) { return null; }
        return $this->forSupplier((int) $item->supplier_id, $item->supplier->code);
    }

    /** Pure frozen-snapshot validation. Missing legacy block is allowed; a present invalid block fails closed. */
    public function frozen(mixed $block): ?array
    {
        if ($block === null) { return null; }
        if (is_array($block) && ($block['schema'] ?? null) === 'iapi.warranty.zoommer-dealer.v1') {
            return app(WarrantyZoommerDealerForm::class)->frozen($block);
        }
        if (! is_array($block) || array_diff(array_keys($block), ['schema', 'id', 'supplier_id', 'supplier_code', 'source', 'title', 'paragraphs']) !== []
            || ($block['schema'] ?? null) !== 'iapi.warranty.elite-dealer.v1' || ($block['id'] ?? null) !== 'elite.dealer.2024'
            || ($block['supplier_id'] ?? null) !== 3 || ($block['supplier_code'] ?? null) !== 'elite'
            || ! is_array($block['source'] ?? null)
            || array_diff(array_keys($block['source']), ['filename', 'sha256']) !== []
            || ! is_string($block['source']['filename'] ?? null) || trim($block['source']['filename']) === ''
            || mb_strlen($block['source']['filename']) > 255
            || ($block['source']['sha256'] ?? null) !== self::SOURCE_SHA256
            || ! is_string($block['title'] ?? null) || trim($block['title']) === '' || mb_strlen($block['title']) > 1000
            || ! is_array($block['paragraphs'] ?? null) || ! array_is_list($block['paragraphs'])
            || count($block['paragraphs']) < 1 || count($block['paragraphs']) > 200) { $this->invalid(); }
        $length = 0;
        foreach ($block['paragraphs'] as $paragraph) {
            if (! is_string($paragraph) || trim($paragraph) === '' || mb_strlen($paragraph) > 15000
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $paragraph)) { $this->invalid(); }
            $length += mb_strlen($paragraph);
        }
        if ($length > 150000) { $this->invalid(); }
        // Do not trim/reflow/reword the supplier's exact paragraphs or convert them into executable markup.
        return $block;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['certificate' => __('warranty.errors.dealer_form_invalid')]);
    }
}
