<?php

namespace App\Services\Warranties;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Converts reviewed source facts into a warranty scoped to the actual delivery item. */
class WarrantyApproval
{
    private const EDITABLE = ['service_name', 'service_address', 'service_phone', 'service_email', 'coverage',
        'conditions', 'claim_instructions', 'component_terms', 'start_basis', 'source_reference', 'requires_original'];

    public function __construct(private WarrantyServiceDirectory $directory, private WarrantyEventRecorder $events) {}

    public function preview(WarrantyCertificate $certificate, User $actor): array
    {
        $this->owner($actor);
        [$certificate, $item] = $this->current($certificate);
        return $this->data($certificate, $item);
    }

    /** Staff see the supplier facts directly; viewing never creates or approves anything. */
    public function summary(WarrantyCertificate $certificate, User $actor): array
    {
        if (! $actor->exists || ! $actor->isStaff()) { abort(403); }
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            $snapshot = $certificate->snapshot ?? [];
            return ['service' => data_get($snapshot, 'warranty.service', []),
                'coverage' => data_get($snapshot, 'warranty.coverage'),
                'conditions' => data_get($snapshot, 'warranty.conditions'),
                'claim_instructions' => data_get($snapshot, 'warranty.claim_instructions'),
                'missing_fields' => [], 'auto_ready' => $certificate->status === WarrantyCertificate::ISSUED];
        }
        try { [$current, $item] = $this->current($certificate); }
        catch (ValidationException) {
            return ['service' => [], 'coverage' => null, 'conditions' => null, 'claim_instructions' => null,
                'missing_fields' => ['შეკვეთის მომწოდებელი ან ნივთი შეიცვალა. ხელახლა გახსენით მომზადება.'], 'auto_ready' => false];
        }
        $policy = $current->policy;
        if ($policy && $policy->status === WarrantyPolicy::APPROVED && $current->sale_date
            && app(WarrantyPolicyResolver::class)->matches($policy, $item, $current->sale_date->toDateString())) {
            return ['service' => ['name' => $policy->service_name, 'phone' => $policy->service_phone,
                'email' => $policy->service_email, 'address' => $policy->service_address, 'scope' => null],
                'coverage' => $policy->coverage, 'conditions' => $policy->conditions,
                'claim_instructions' => $policy->claim_instructions, 'missing_fields' => [], 'auto_ready' => true];
        }
        $data = $this->data($current, $item);
        return ['service' => ['name' => $data['service_name'], 'phone' => $data['service_phone'],
            'email' => $data['service_email'], 'address' => $data['service_address'],
            'scope' => $data['_scope_label']], 'coverage' => $data['coverage'], 'conditions' => $data['conditions'],
            'claim_instructions' => $data['claim_instructions'], 'missing_fields' => $data['missing_fields'],
            'auto_ready' => $data['auto_ready']];
    }

    public function canAutoApprove(WarrantyCertificate $certificate): bool
    {
        if ($certificate->status !== WarrantyCertificate::DRAFT) { return false; }
        try {
            [$current, $item] = $this->current($certificate);
            return $this->data($current, $item)['auto_ready'];
        } catch (ValidationException) { return false; }
    }

    public function authorize(WarrantyCertificate $certificate, array $input, User $actor): WarrantyPolicy
    {
        $this->owner($actor);
        return DB::transaction(function () use ($certificate, $input, $actor): WarrantyPolicy {
            [$certificate, $item] = $this->current($certificate, true);
            $preview = $this->data($certificate, $item);
            Validator::make($input, ['approve_as_admin' => ['required', 'accepted'],
                'source_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D']])->validate();
            if (! hash_equals($preview['source_fingerprint'], $input['source_fingerprint'])) {
                $this->invalid('source_fingerprint', 'მომწოდებლის ან პირობების მონაცემები შეიცვალა. თავიდან გახსენით გადამოწმება.');
            }
            $values = array_intersect_key(array_replace($preview, $input), array_flip(self::EDITABLE));
            $rules = [];
            foreach (['service_name', 'service_address', 'service_phone', 'service_email', 'component_terms', 'conditions', 'source_reference'] as $field) {
                $rules[$field] = ['nullable', 'string', 'max:20000'];
            }
            $rules['service_email'] = ['nullable', 'email', 'max:255'];
            $rules['coverage'] = ['required', 'string', 'max:20000'];
            $rules['claim_instructions'] = ['required', 'string', 'max:15000'];
            $rules['start_basis'] = ['required', 'in:sale_date,delivery_date,manufacturer_date'];
            $rules['requires_original'] = ['required', 'boolean'];
            $values = Validator::make($values, $rules, [], ['coverage' => 'საგარანტიო დაფარვა',
                'claim_instructions' => 'მომსახურების მიღების წესი'])->validate();
            $missing = $this->missing($values, $preview['_dealer_hotline_only']);
            $needsReview = ! $preview['auto_ready'] || $preview['missing_fields'] !== [] || $missing !== [];
            $reason = Validator::make($input, ['review_note' => [$needsReview ? 'required' : 'nullable', 'string',
                $needsReview ? 'min:3' : 'min:0', 'max:5000']], [], ['review_note' => 'ადმინის დადასტურების განმარტება'])->validate()['review_note'] ?? null;
            return $this->store($certificate, $item, $values, $actor, 'owner_confirmed_item',
                $preview, $missing, $reason);
        });
    }

    /** Called only by the staff's explicit Issue action, never mount/render/preparation. */
    public function fromConfirmedDirectory(WarrantyCertificate $certificate, User $actor): ?WarrantyPolicy
    {
        if (! $actor->exists || ! $actor->isStaff()) { $this->invalid('authorization', 'ტალონის დადასტურება მხოლოდ თანამშრომელს შეუძლია.'); }
        return DB::transaction(function () use ($certificate, $actor): ?WarrantyPolicy {
            [$certificate, $item] = $this->current($certificate, true);
            $preview = $this->data($certificate, $item);
            if (! $preview['auto_ready']) { return null; }
            return $this->store($certificate, $item, array_intersect_key($preview, array_flip(self::EDITABLE)),
                $actor, $preview['_full_source_confirmed'] ? 'verified_directory' : 'supplier_profile',
                $preview, $preview['missing_fields'], null);
        });
    }

    public function matchesBoundPolicy(WarrantyPolicy $policy, OrderDeliveryItem $item): bool
    {
        if (! in_array($policy->approval_mode, ['verified_directory', 'owner_confirmed_item', 'supplier_profile'], true)
            || ! $policy->order_delivery_item_id || (int) $policy->order_delivery_item_id !== (int) $item->id
            || ! is_array($policy->approval_context)) { return false; }
        $hash = $policy->approval_context['identity_sha256'] ?? null;
        if (! is_string($hash) || ! hash_equals($hash, $this->identityFingerprint($item))) { return false; }
        if (in_array($policy->approval_mode, ['verified_directory', 'supplier_profile'], true)) {
            $route = $this->directory->resolve($item);
            $savedRouteKey = $policy->approval_context['route_key'] ?? null;
            $wasCustomRoute = is_string($savedRouteKey)
                && preg_match('/\Aowner\.[0-9A-HJKMNP-TV-Z]{26}\z/D', $savedRouteKey) === 1;
            // A disabled custom route can fall back to an unprofiled official route. Its previous
            // generated policy must still be refreshed at the next explicit issue, never silently reused.
            $alta = (int) $item->supplier_id === 2 && $item->supplier?->code === 'alta';
            if (isset($route['_profile_fingerprint']) || $wasCustomRoute || $alta) {
                $source = hash('sha256', json_encode([$this->identityFingerprint($item), $route, config('warranty.seller')],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $saved = $policy->approval_context['source_sha256'] ?? null;
                if (! is_string($saved) || ! hash_equals($saved, $source)) { return false; }
            }
        }
        return true;
    }

    public function identityFingerprint(OrderDeliveryItem $item): string
    {
        $item->loadMissing(['supplier', 'product.brand', 'product.category']);
        $categories = [];
        $node = $item->product?->category;
        for ($i = 0; $node && $i < 16 && ! isset($categories[$node->id]); $i++, $node = $node->parent) {
            $categories[$node->id] = $node->name;
        }
        return hash('sha256', json_encode([$item->id, $item->order_delivery_id, $item->supplier_id, $item->supplier?->code,
            $item->product_id, $item->sku, $item->name, $item->supplier_sku, $item->product?->brand_id,
            $item->product?->brand?->name, $categories], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function data(WarrantyCertificate $certificate, OrderDeliveryItem $item): array
    {
        $route = $this->directory->resolve($item);
        $terms = $this->directory->officialTerms($route);
        $dealerSource = $this->directory->dealerTermsSource($route);
        $hotlineOnly = $dealerSource !== null && ($route['_dealer_hotline_only'] ?? false)
            && ! in_array('service_name', $route['_profile_changed_fields'] ?? [], true);
        $hasContact = ($route['contact_confirmed'] ?? false) || ($route['_profile_owner_entered'] ?? false);
        $seller = config('warranty.seller');
        $join = static fn ($lines) => implode("\n", is_array($lines) ? $lines : []);
        $claims = $join($terms['claims'] ?? []);
        if ($claims === '' && $hasContact) {
            $contact = array_values(array_filter([$route['service_phone'] ?? null, $route['service_email'] ?? null,
                $route['service_address'] ?? null], fn ($value) => is_string($value) && trim($value) !== ''));
            $claims = 'საგარანტიო მომსახურებისთვის დაუკავშირდით '.($route['service_name'] ?? '').': '.implode(' · ', $contact).'.';
        } elseif ($claims === '') {
            $claims = 'საგარანტიო საკითხზე მიმართეთ '.($seller['name'] ?? '').': '.($seller['phone'] ?? '')
                .'; '.($seller['email'] ?? '').'; '.($seller['address'] ?? '').'.';
        }
        $values = ['service_name' => $hasContact ? ($route['service_name'] ?? null) : null,
            'service_address' => $hasContact ? ($route['service_address'] ?? null) : null,
            'service_phone' => $hasContact ? ($route['service_phone'] ?? null) : null,
            'service_email' => $hasContact ? ($route['service_email'] ?? null) : null,
            'coverage' => $join($terms['coverage'] ?? []),
            'conditions' => $join(array_merge($terms['exclusions'] ?? [], $terms['customer_duties'] ?? [])),
            'claim_instructions' => $claims, 'component_terms' => $join($terms['component_terms'] ?? []),
            'start_basis' => in_array($route['official_terms']['start_basis'] ?? null, ['sale_date','delivery_date','manufacturer_date'], true)
                ? $route['official_terms']['start_basis'] : 'sale_date',
            'source_reference' => $join(array_values(array_filter(array_merge(array_column($terms['sources'] ?? [], 'url'),
                [$dealerSource !== null ? implode(' · ', $dealerSource) : null,
                    is_string($route['_profile_source_text'] ?? null) ? $route['_profile_source_text'] : null])))),
            // This controls an internal electronic attachment, not the supplier's original-card requirement.
            'requires_original' => false];
        $sourceMissing = array_values(array_filter($terms['missing_fields'] ?? [], fn ($text) => is_string($text)
            && ! (filled($values['service_address']) && in_array($text, ['შესაბამისი სერვისცენტრის ზუსტი მისამართი',
                'სერვისის ზუსტი მისამართი', 'სერვისის მიღების ზუსტი მისამართი'], true))));
        $missing = array_values(array_unique(array_merge($this->missing($values, $hotlineOnly), $sourceMissing)));
        $fullSource = $route && ($route['routing_confirmed'] ?? false) && ($route['contact_confirmed'] ?? false)
            && ($terms['status'] ?? null) === 'substantive' && $missing === []
            && ($terms['coverage'] ?? []) !== [] && ($terms['exclusions'] ?? []) !== [] && ($terms['claims'] ?? []) !== []
            && ! ($route['_profile_owner_entered'] ?? false);
        foreach ($terms['customer_duties'] ?? [] as $text) {
            if (str_contains($text, 'პირველი გაშვება')) { $fullSource = false; }
        }
        // The supplier profile is maintained once. Missing optional facts stay blank, never invented.
        // Commissioning conditions remain in the document; no commissioning date or act is inferred here.
        $auto = $route && ($route['routing_confirmed'] ?? false) && $hasContact
            && filled($values['service_name']) && (filled($values['service_phone']) || filled($values['service_email']))
            && ($dealerSource === null || ($terms !== null && $missing === []));
        return $values + ['missing_fields' => array_values(array_unique($missing)), 'auto_ready' => (bool) $auto,
            'source_fingerprint' => hash('sha256', json_encode([$this->identityFingerprint($item), $route, $seller],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            '_source_confirmed' => $terms !== null, '_full_source_confirmed' => (bool) $fullSource,
            '_scope_label' => $route['scope_label_ka'] ?? null, '_route_key' => $route['key'] ?? null,
            '_sources' => $terms['sources'] ?? [],
            '_dealer_terms_source' => $dealerSource, '_dealer_hotline_only' => $hotlineOnly];
    }

    private function missing(array $values, bool $hotlineOnly): array
    {
        $missing = [];
        foreach (['coverage' => 'საგარანტიო დაფარვა', 'service_name' => 'სერვისცენტრის დასახელება',
            'service_address' => 'სერვისცენტრის მისამართი'] as $field => $label) {
            if (($field !== 'service_address' || ! $hotlineOnly) && ! filled($values[$field] ?? null)) { $missing[] = $label; }
        }
        if (! filled($values['service_phone'] ?? null) && ! filled($values['service_email'] ?? null)) { $missing[] = 'სერვისის საკონტაქტო ნომერი ან ელფოსტა'; }
        return $missing;
    }

    private function store(WarrantyCertificate $certificate, OrderDeliveryItem $item, array $values, User $actor,
        string $mode, array $preview, array $missing, ?string $reason): WarrantyPolicy
    {
        // Do not make a correction on this line affect any other product/order/supplier.
        foreach (WarrantyPolicy::where('order_delivery_item_id', $item->id)->where('status', WarrantyPolicy::APPROVED)->lockForUpdate()->get() as $old) {
            $old->forceFill(['status' => WarrantyPolicy::ARCHIVED, 'updated_by' => $actor->id])->save();
        }
        $seller = config('warranty.seller');
        $values = array_map(fn ($value) => $value === '' ? null : $value, $values);
        $policy = WarrantyPolicy::create($values + ['family_uuid' => (string) Str::uuid(), 'version' => 1,
            'status' => WarrantyPolicy::APPROVED, 'supplier_id' => $item->supplier_id, 'product_id' => $item->product_id,
            'brand_id' => null, 'category_id' => null, 'order_delivery_item_id' => $item->id, 'approval_mode' => $mode,
            'name' => match ($mode) { 'verified_directory' => 'დადასტურებული წყარო — ',
                'supplier_profile' => 'მომწოდებლის საგარანტიო — ', default => 'ადმინის დადასტურება — ' }.mb_substr($item->name, 0, 170),
            'issuer_name' => $seller['name'] ?? null, 'issuer_address' => $seller['address'] ?? null,
            'issuer_contact' => ($seller['phone'] ?? '').' · '.($seller['email'] ?? ''), 'territory' => 'საქართველო',
            'source_confirmed' => $mode === 'verified_directory',
            'source_url' => $preview['_sources'][0]['url'] ?? null,
            'customer_reference' => null, 'review_notes' => $reason,
            'original_not_required_reason' => $values['requires_original'] ? null : 'ელექტრონული ორიგინალის ატვირთვა არ არის სავალდებულო. მომწოდებლის მიერ მოთხოვნილი ტალონი და შეძენის საბუთი უნდა შეინახოთ.',
            'approval_context' => ['identity_sha256' => $this->identityFingerprint($item), 'certificate_id' => $certificate->id,
                'route_key' => $preview['_route_key'], 'source_sha256' => $preview['source_fingerprint'],
                'official_sources' => $preview['_sources'], 'reviewed_missing_fields' => array_values(array_unique(array_merge($preview['missing_fields'], $missing))),
                'owner_review_note' => $reason, 'actor_id' => $actor->id]
                + ($preview['_dealer_terms_source'] === null ? [] : ['dealer_terms_source' => $preview['_dealer_terms_source']]),
            'created_by' => $actor->id, 'updated_by' => $actor->id,
            'approved_by' => $mode === 'owner_confirmed_item' ? $actor->id : null, 'approved_at' => now()]);
        $certificate->forceFill(['policy_id' => $policy->id, 'updated_by' => $actor->id])->save();
        $this->events->record(match ($mode) { 'verified_directory' => 'policy_from_verified_source',
            'supplier_profile' => 'policy_from_supplier_profile', default => 'policy_owner_confirmed' },
            $actor, $certificate, $policy, ['reason' => $reason, 'scope' => 'delivery_item:'.$item->id,
                'reference' => $preview['source_fingerprint']]);
        return $policy;
    }

    private function current(WarrantyCertificate $certificate, bool $lock = false): array
    {
        if (! $certificate->exists || ! $certificate->order_delivery_item_id) { $this->invalid('certificate', 'საგარანტიო ტალონი ვერ მოიძებნა.'); }
        $item = OrderDeliveryItem::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($certificate->order_delivery_item_id);
        $certificate = WarrantyCertificate::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($certificate->id);
        $item->loadMissing(['delivery.order', 'supplier', 'product.brand', 'product.category']);
        if ($certificate->status !== WarrantyCertificate::DRAFT || ! $item->is_active || ! $item->supplier_id || ! $item->supplier
            || (int) $certificate->supplier_id !== (int) $item->supplier_id
            || (int) $certificate->order_delivery_item_id !== (int) $item->id
            || (int) $certificate->order_id !== (int) $item->delivery?->order_id
            || ! $item->delivery?->order || $item->delivery->order->trashed()
            || $item->delivery->order->status === Order::CANCELLED || $item->delivery->status === OrderDelivery::CANCELLED
            || (in_array($item->supplier->code, ['citrus','elitlight','christmas-trees-1'], true)
                && ! WarrantySupplierIdentity::isSkytech((int) $item->supplier_id, $item->supplier->code))) {
            $this->invalid('certificate', 'შეკვეთის ან მომწოდებლის მონაცემები შეიცვალა. თავიდან გადაამოწმეთ ტალონი.');
        }
        return [$certificate, $item];
    }

    private function owner(User $actor): void
    {
        if (! $actor->exists || ! $actor->isAdmin()) { $this->invalid('authorization', 'პირობების ხელით დადასტურება მხოლოდ ადმინს შეუძლია.'); }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
