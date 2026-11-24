<?php

namespace App\Services\Warranties;

use App\Models\WarrantyCertificate;

class WarrantySnapshotBuilder
{
    public function draft(WarrantyCertificate $certificate): array
    {
        if ($certificate->status !== WarrantyCertificate::DRAFT && is_array($certificate->snapshot)) {
            return $certificate->snapshot;
        }
        return $this->build($certificate);
    }

    public function build(WarrantyCertificate $certificate): array
    {
        $certificate->loadMissing(['deliveryItem.product.brand', 'deliveryItem.delivery.order', 'supplier', 'policy.documents', 'documents']);
        $item = $certificate->deliveryItem;
        $supplierIdentity = app(WarrantySupplierIdentity::class)->resolve($item, $certificate);
        $policy = $certificate->policy;
        $approvedPolicy = $policy?->status === 'approved';
        if ($approvedPolicy && (! $item || ! $certificate->sale_date
            || ! app(WarrantyPolicyResolver::class)->matches($policy, $item, $certificate->sale_date->toDateString()))) {
            // A same-supplier policy for another brand/category is not this unit's guarantee.
            $policy = null;
            $approvedPolicy = false;
        }
        // The directory is preparation guidance. It must never overwrite approved contractual terms.
        $directory = app(WarrantyServiceDirectory::class);
        $service = $approvedPolicy ? null : $directory->resolve($item);
        $manualPeriod = filled($certificate->manual_term_value) && filled($certificate->manual_term_unit);
        $termValue = $manualPeriod ? $certificate->manual_term_value : null;
        $termUnit = $manualPeriod ? $certificate->manual_term_unit : null;
        $basis = $policy?->start_basis;
        $start = in_array($basis, ['sale_date', 'delivery_date', 'manufacturer_date'], true) ? $certificate->{$basis} : null;
        $end = $start?->copy();
        if ($end && $termValue && in_array($termUnit, ['days', 'months', 'years'], true)) {
            $end = match ($termUnit) {
                'days' => $end->addDays($termValue),
                'months' => $end->addMonthsNoOverflow($termValue),
                'years' => $end->addYearsNoOverflow($termValue),
            };
        } else {
            $end = null;
        }
        $storedSourceMatches = $item && (int) $certificate->supplier_id === (int) $item->supplier_id
            && (int) $certificate->product_id === (int) $item->product_id;
        if (filled($certificate->supplier_native_sku_identity_sha256)
            && (! is_string($supplierIdentity['identity_hash'] ?? null)
                || ! hash_equals($certificate->supplier_native_sku_identity_sha256, $supplierIdentity['identity_hash']))) {
            $storedSourceMatches = false;
        }
        $forms = $certificate->status === WarrantyCertificate::DRAFT && ! $storedSourceMatches
            ? collect() : $certificate->documents->where('role', 'completed_original');
        if ($policy && $policy->status === 'approved') {
            $forms = $forms->concat($policy->documents->where('role', 'terms'));
        }
        $termText = null;
        if (! filled($termText) && $termValue && $termUnit) {
            $termText = __('warranty.term_text.'.$termUnit, ['count' => $termValue], 'ka');
        }
        $snapshot = ['schema' => 'iapi.warranty.v1',
            'certificate' => ['number' => $certificate->number, 'revision' => $certificate->revision,
                'status' => $certificate->status, 'issued_at' => $certificate->issued_at?->toIso8601String()],
            'seller' => config('warranty.seller', ['name' => 'შპს „უნიპრო“', 'tax_id' => '416353635',
                'address' => 'თბილისი, შარტავას ქ. №3', 'phone' => '0322 12 10 28', 'email' => 'info@iapi.ge']),
            'customer' => ['name' => $certificate->customer_name, 'phone' => $certificate->customer_phone],
            'item' => ['name' => $item?->name, 'brand' => $item?->product?->brand?->name, 'model' => $item?->name,
                'sku' => $item?->sku, 'supplier_id' => $certificate->supplier_id,
                'supplier_name' => app(WarrantyServiceDirectory::class)->supplierName($item),
                'supplier_legal_name' => app(WarrantyServiceDirectory::class)->supplierLegalName($item),
                'supplier_sku' => $supplierIdentity['native_sku'], 'quantity' => 1, 'unit_index' => $certificate->unit_index,
                'serial_number' => $certificate->serial_number, 'not_serialized_reason' => $certificate->not_serialized_reason,
                'order_number' => $item?->delivery?->order?->number],
            'dates' => ['order_date' => $item?->delivery?->order?->created_at?->toDateString(),
                'sale_date' => $certificate->sale_date?->toDateString(), 'delivery_date' => $certificate->delivery_date?->toDateString(),
                'sale_date_source' => $certificate->sale_date_source,
                'sale_date_source_event_id' => $certificate->sale_date_source_event_id,
                'sale_date_source_label' => __('warranty.sale_date_sources.'.($certificate->sale_date_source
                    ?: ($certificate->sale_date ? 'legacy_manual' : 'missing')), [], 'ka')],
            'warranty' => ['policy_id' => $policy?->id, 'version' => $policy?->version, 'name' => $policy?->name,
                'policy_approved' => $approvedPolicy,
                'reference_terms' => $approvedPolicy ? null : $directory->officialTerms($service),
                'issuer_name' => $policy?->issuer_name, 'issuer_address' => $policy?->issuer_address, 'issuer_contact' => $policy?->issuer_contact,
                'term_value' => $termValue, 'term_unit' => $termUnit, 'term_text' => $termText,
                'starts_on' => $start?->toDateString(), 'ends_on' => $end?->toDateString(), 'territory' => $policy?->territory,
                'coverage' => $policy?->coverage, 'component_terms' => $policy?->component_terms,
                'conditions' => $service ? ($service['conditions_confirmed'] ? $service['conditions'] : null) : $policy?->conditions,
                'claim_instructions' => $policy?->claim_instructions, 'service' => [
                    'name' => $service ? $service['service_name'] : $policy?->service_name,
                    'address' => $service ? $service['service_address'] : $policy?->service_address,
                    'phone' => $service ? $service['service_phone'] : $policy?->service_phone,
                    'email' => $service ? $service['service_email'] : $policy?->service_email,
                    'scope' => $service['scope_label_ka'] ?? null],
                // Procurement evidence and staff review notes remain on the private policy record.
                'source_url' => null, 'source_reference' => filled($policy?->customer_reference)
                    ? $policy->customer_reference
                    : ($policy ? __('warranty.customer_policy_reference', ['id' => $policy->id, 'version' => $policy->version], 'ka') : null),
                'original_requirement' => $policy?->requires_original ? 'completed_original' : null],
            'original_forms' => $forms->map(fn ($document) => ['id' => $document->id, 'label' => $document->label,
                'sha256' => $document->sha256, 'mime_type' => $document->mime_type, 'role' => $document->role])->values()->all(),
            'legal' => collect(['rights_text', 'seller_responsibility_text', 'identity_text', 'mark_notice'])
                ->mapWithKeys(fn ($key) => [$key => __('warranty.legal.'.$key, [], 'ka')])->all()];
        if ((int) $certificate->supplier_id === 2 && (int) $item?->supplier_id === 2 && $item->supplier?->code === 'alta') {
            $snapshot['warranty']['paper_signatures_required'] = true;
        }
        $dealerForm = app(WarrantyDealerForm::class)->forCertificate($certificate);
        if ($dealerForm !== null) { $snapshot['supplier_dealer_form'] = $dealerForm; }
        return $snapshot;
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonical($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }
        return $value;
    }
}
