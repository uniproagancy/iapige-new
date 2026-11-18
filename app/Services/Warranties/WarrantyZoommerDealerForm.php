<?php

namespace App\Services\Warranties;

use App\Models\OrderDeliveryItem;
use App\Models\WarrantyCertificate;
use Illuminate\Validation\ValidationException;

/** The supplier's 2024 dealer card covers four equipment categories, never their accessories. */
class WarrantyZoommerDealerForm
{
    private const SOURCE_SHA256 = '6a880888de6d44513f521c968cb34b2d1719efe37e21276877020c6732b92812';

    private const RESOURCE_SHA256 = '9c27c30eb69b23bb0b496974815d63ac589befddd7fa1ee58d91a893d946d0d2';

    private const CATEGORY_NAMES = [
        'მობილური ტელეფონი', 'მობილური ტელეფონები', 'მობილურები', 'სმარტფონი', 'სმარტფონები',
        'mobile phone', 'mobile phones', 'smartphone', 'smartphones',
        'პლანშეტი', 'პლანშეტები', 'ტაბი', 'ტაბები', 'tablet', 'tablets',
        'ტელევიზორი', 'ტელევიზორები', 'tv', 'television', 'televisions',
        'ლეპტოპი', 'ლეპტოპები', 'ნოუთბუქი', 'ნოუთბუქები', 'გეიმინგ ლეპტოპი', 'გეიმინგ ლეპტოპები',
        'laptop', 'laptops', 'notebook', 'notebooks', 'gaming laptop', 'gaming laptops',
    ];

    public function forSupplier(int $supplierId, string $supplierCode): ?array
    {
        if ($supplierId !== 1 || $supplierCode !== 'zoommer') {
            return null;
        }
        $path = resource_path('warranty/zoommer-dealer-2024.json');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 256 * 1024) {
            $this->invalid();
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals(self::RESOURCE_SHA256, hash('sha256', $bytes))) {
            $this->invalid();
        }
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid();
        }

        return $this->frozen($data);
    }

    public function forCertificate(WarrantyCertificate $certificate): ?array
    {
        if (in_array($certificate->getRawOriginal('status'), [WarrantyCertificate::ISSUED, WarrantyCertificate::REVOKED], true)) {
            return $this->frozen($certificate->snapshot['supplier_dealer_form'] ?? null);
        }
        $item = $certificate->order_delivery_item_id
            ? OrderDeliveryItem::with(['supplier', 'delivery', 'product.category'])->find($certificate->order_delivery_item_id) : null;
        if (! $item || ! $item->is_active || (int) $item->supplier_id !== 1 || $item->supplier?->code !== 'zoommer'
            || (int) $certificate->supplier_id !== (int) $item->supplier_id
            || (int) $certificate->product_id !== (int) $item->product_id
            || (int) $certificate->order_id !== (int) $item->delivery?->order_id
            || (int) $certificate->order_delivery_item_id !== (int) $item->id
            || ! $this->eligibleCategory($item)) {
            return null;
        }
        $proof = $certificate->supplier_native_sku_identity_sha256;
        $identity = filled($proof) ? app(WarrantySupplierIdentity::class)->identityHash($item) : null;
        if (filled($proof) && (! $identity || ! hash_equals($proof, $identity))) {
            return null;
        }

        return $this->forSupplier((int) $item->supplier_id, $item->supplier->code);
    }

    private function eligibleCategory(OrderDeliveryItem $item): bool
    {
        $node = $item->product?->category;
        $seen = [];
        $positive = false;
        for ($depth = 0; $node && $depth < 16; $depth++) {
            $id = (int) $node->id;
            if (! $id || in_array($id, $seen, true)) {
                return false;
            }
            $seen[] = $id;
            $name = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $node->name)));
            // A broad grouping is neither positive equipment evidence nor an accessory leaf.
            $mixedParent = $depth > 0 && in_array($name, ['ტელევიზორები და აქსესუარები',
                'მობილური ტელეფონები და აქსესუარები', 'ლეპტოპები და აქსესუარები',
                'პლანშეტები და აქსესუარები', 'tv and accessories', 'phones and accessories',
                'laptops and accessories', 'tablets and accessories'], true);
            // An equipment ancestor must not turn a phone case, TV stand or laptop part into equipment.
            if (! $mixedParent && preg_match('/აქსესუარ|ქეის|ჩასადებ|გარსაცმ|დამტენ|კაბელ|ადაპტერ|სადგამ|სამაგრ|დამცავ|მინა|ეკრან|ბატარე|ელემენტ|აკუმულ|კლავიატურ|მაუს|დინამიკ|ყურსასმენ|სათადარიგ|პულტ|შლეიფ|ნაწილ|ჩანთა|მაგიდა|accessor|\b(case|cases|cover|covers|charger|chargers|cable|cables|adapter|adapters|stand|stands|mount|mounts|protector|protectors|screen|screens|battery|batteries|keyboard|keyboards|mouse|speaker|speakers|headphone|headphones|earphone|earphones|part|parts|remote|remotes|bag|bags|desk|desks|table|tables)\b/u', $name)) {
                return false;
            }
            if (in_array($name, self::CATEGORY_NAMES, true)) {
                $positive = true;
            }
            $parentId = (int) $node->parent_id;
            $node = $parentId ? $node->parent : null;
            // Broken or excessively deep ancestry is not positive evidence for this dealer card.
            if ($parentId && ! $node) {
                return false;
            }
        }

        return $node === null && $positive;
    }

    /** Pure frozen validation: a missing legacy block stays missing; no current-resource fallback. */
    public function frozen(mixed $block): ?array
    {
        if ($block === null) {
            return null;
        }
        if (! is_array($block) || array_diff(array_keys($block), ['schema', 'id', 'supplier_id', 'supplier_code', 'source',
            'title', 'paragraphs', 'scope_notice', 'current_service', 'contact_sources']) !== []
            || ($block['schema'] ?? null) !== 'iapi.warranty.zoommer-dealer.v1' || ($block['id'] ?? null) !== 'zoommer.dealer.2024'
            || ($block['supplier_id'] ?? null) !== 1 || ($block['supplier_code'] ?? null) !== 'zoommer'
            || ! is_array($block['source'] ?? null) || array_diff(array_keys($block['source']), ['filename', 'sha256']) !== []
            || ! $this->text($block['source']['filename'] ?? null, 255)
            || ($block['source']['sha256'] ?? null) !== self::SOURCE_SHA256
            || ! $this->text($block['title'] ?? null, 1000) || ! $this->text($block['scope_notice'] ?? null, 3000)
            || ! is_array($block['paragraphs'] ?? null) || ! array_is_list($block['paragraphs'])
            || count($block['paragraphs']) < 1 || count($block['paragraphs']) > 200) {
            $this->invalid();
        }
        $length = 0;
        foreach ($block['paragraphs'] as $paragraph) {
            if (! $this->text($paragraph, 15000)) {
                $this->invalid();
            }
            $length += mb_strlen($paragraph);
        }
        if ($length > 150000) {
            $this->invalid();
        }
        $service = $block['current_service'] ?? null;
        if (! is_array($service) || array_diff(array_keys($service), ['name', 'phone', 'email', 'branches']) !== []
            || ! $this->text($service['name'] ?? null, 1000) || ! $this->text($service['phone'] ?? null, 255)
            || ! is_string($service['email'] ?? null) || ! filter_var($service['email'], FILTER_VALIDATE_EMAIL)
            || ! is_array($service['branches'] ?? null) || ! array_is_list($service['branches'])
            || count($service['branches']) < 1 || count($service['branches']) > 20) {
            $this->invalid();
        }
        foreach ($service['branches'] as $branch) {
            if (! is_array($branch) || array_diff(array_keys($branch), ['city', 'address']) !== []
                || ! $this->text($branch['city'] ?? null, 255) || ! $this->text($branch['address'] ?? null, 2000)) {
                $this->invalid();
            }
        }
        if (! is_array($block['contact_sources'] ?? null) || ! array_is_list($block['contact_sources'])
            || count($block['contact_sources']) < 1 || count($block['contact_sources']) > 10) {
            $this->invalid();
        }
        foreach ($block['contact_sources'] as $source) {
            if (! is_array($source) || array_diff(array_keys($source), ['url', 'title', 'checked_on']) !== []
                || ! in_array($source['url'] ?? null, ['https://zoommer.ge/info/garantia', 'https://zoommer.ge/en/info/garantia'], true)
                || ! $this->text($source['title'] ?? null, 1000) || ! is_string($source['checked_on'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $source['checked_on'])) {
                $this->invalid();
            }
        }

        return $block;
    }

    private function text(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max
            && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['certificate' => __('warranty.errors.dealer_form_invalid')]);
    }
}
