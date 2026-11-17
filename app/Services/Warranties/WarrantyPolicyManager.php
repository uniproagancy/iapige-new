<?php

namespace App\Services\Warranties;

use App\Models\User;
use App\Models\WarrantyDocument;
use App\Models\WarrantyPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyPolicyManager
{
    public function __construct(private WarrantyEventRecorder $events) {}

    public function saveDraft(?WarrantyPolicy $policy, array $data, User $actor): WarrantyPolicy
    {
        $this->owner($actor);
        $valid = $this->validated($data, false);

        return DB::transaction(function () use ($policy, $valid, $actor): WarrantyPolicy {
            $current = $policy?->exists ? WarrantyPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail() : null;
            if ($current?->approval_mode) {
                throw ValidationException::withMessages(['policy' => 'ამ შეკვეთის პირობები შეცვალეთ უშუალოდ საგარანტიო ტალონიდან.']);
            }
            if ($current && $current->status !== WarrantyPolicy::DRAFT) {
                $versions = WarrantyPolicy::where('family_uuid', $current->family_uuid)->lockForUpdate()->get();
                $draft = $versions->where('status', WarrantyPolicy::DRAFT)->sortByDesc('version')->first();
                $target = $draft ?? new WarrantyPolicy([...$current->only(array_keys($this->rules(false))),
                    'family_uuid' => $current->family_uuid, 'version' => (int) $versions->max('version') + 1,
                    'status' => WarrantyPolicy::DRAFT, 'source_confirmed' => false, 'created_by' => $actor->id]);
            } else {
                $target = $current ?? new WarrantyPolicy(['family_uuid' => (string) Str::uuid(),
                    'version' => 1, 'status' => WarrantyPolicy::DRAFT, 'created_by' => $actor->id]);
            }
            $isNew = ! $target->exists;
            $target->fill($valid);
            if ($isNew && $current && $current->status !== WarrantyPolicy::DRAFT) {
                $target->source_confirmed = false;
            }
            $target->forceFill(['updated_by' => $actor->id])->save();
            if ($isNew && $current && $current->id !== $target->id) {
                foreach ($current->documents as $document) {
                    WarrantyDocument::create([...$document->only(['role', 'label', 'original_name', 'mime_type', 'disk', 'path', 'sha256', 'bytes']),
                        'policy_id' => $target->id, 'uploaded_by' => $document->uploaded_by]);
                }
            }
            $this->events->record($isNew ? 'policy_draft_created' : 'policy_draft_updated', $actor, policy: $target);
            return $target->fresh();
        });
    }

    public function approve(WarrantyPolicy $policy, User $actor): WarrantyPolicy
    {
        $this->owner($actor);
        return DB::transaction(function () use ($policy, $actor): WarrantyPolicy {
            $policy = WarrantyPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($policy->status === WarrantyPolicy::APPROVED) {
                return $policy;
            }
            if ($policy->status !== WarrantyPolicy::DRAFT) {
                $this->invalid('policy', 'policy_not_draft');
            }
            $data = $policy->only(array_keys($this->rules(true)));
            $data['valid_from'] = $policy->valid_from?->toDateString();
            $data['valid_until'] = $policy->valid_until?->toDateString();
            $this->validated($data, true);
            if (! $policy->source_confirmed) {
                $this->invalid('source_confirmed', 'source_confirmation_required');
            }
            if (! $policy->requires_original && ! filled($policy->original_not_required_reason)) {
                $this->invalid('original_not_required_reason', 'original_waiver_required');
            }
            if (! filled($policy->service_phone) && ! filled($policy->service_email)) {
                $this->invalid('service_phone', 'service_contact_required');
            }
            foreach ($policy->documents as $document) {
                app(WarrantyDocumentStore::class)->path($document);
            }
            $policy->forceFill(['status' => WarrantyPolicy::APPROVED, 'approved_by' => $actor->id,
                'approved_at' => now(), 'updated_by' => $actor->id])->save();
            $this->events->record('policy_approved', $actor, policy: $policy);
            return $policy->fresh();
        });
    }

    public function archive(WarrantyPolicy $policy, User $actor): WarrantyPolicy
    {
        $this->owner($actor);
        return DB::transaction(function () use ($policy, $actor): WarrantyPolicy {
            $policy = WarrantyPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($policy->status !== WarrantyPolicy::ARCHIVED) {
                $policy->forceFill(['status' => WarrantyPolicy::ARCHIVED, 'updated_by' => $actor->id])->save();
                $this->events->record('policy_archived', $actor, policy: $policy);
            }
            return $policy;
        });
    }

    protected function validated(array $data, bool $complete): array
    {
        $rules = $this->rules($complete);
        $rules['term_value'][] = 'max:'.match ($data['term_unit'] ?? null) {
            'years' => 100,
            'months' => 1200,
            default => 36500,
        };
        if (filled($data['valid_from'] ?? null)) {
            $rules['valid_until'][] = 'after_or_equal:valid_from';
        }
        $valid = Validator::make($data, $rules)->validate();
        return array_map(fn ($value) => $value === '' ? null : $value, $valid);
    }

    protected function rules(bool $complete): array
    {
        $required = $complete ? 'required' : 'nullable';
        return ['supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'], 'name' => ['required', 'string', 'max:255'],
            // Supplier period may be retained as reference; each certificate requires its own explicit period.
            'term_value' => ['nullable', 'required_with:term_unit', 'integer', 'min:1', 'max:36500'],
            'term_unit' => ['nullable', 'required_with:term_value', 'in:days,months,years'],
            'term_text' => ['nullable', 'string', 'max:2000'], 'component_terms' => ['nullable', 'string', 'max:15000'],
            'review_notes' => ['nullable', 'string', 'max:20000'],
            'customer_reference' => ['nullable', 'string', 'max:1000'],
            'issuer_name' => [$required, 'string', 'max:1000'], 'issuer_address' => [$required, 'string', 'max:2000'],
            'issuer_contact' => [$required, 'string', 'max:2000'], 'territory' => [$required, 'string', 'max:1000'],
            'coverage' => [$required, 'string', 'max:20000'], 'conditions' => ['nullable', 'string', 'max:20000'],
            'claim_instructions' => [$required, 'string', 'max:15000'],
            'start_basis' => [$complete ? 'required' : 'nullable', 'in:sale_date,delivery_date,manufacturer_date'],
            'service_name' => [$required, 'string', 'max:1000'], 'service_address' => [$required, 'string', 'max:2000'],
            'service_phone' => ['nullable', 'string', 'max:255'], 'service_email' => ['nullable', 'email', 'max:255'],
            'source_url' => ['nullable', 'url:https', 'max:3000'], 'source_reference' => [$required, 'string', 'max:5000'],
            'source_confirmed' => [$complete ? 'required' : 'sometimes', 'boolean'], 'requires_original' => [$complete ? 'required' : 'sometimes', 'boolean'],
            'original_not_required_reason' => ['nullable', 'string', 'max:5000'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d']];
    }

    protected function owner(User $actor): void
    {
        if (! $actor->exists || ! $actor->isAdmin()) {
            $this->invalid('authorization', 'owner_required');
        }
    }

    protected function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('warranty.errors.'.$key)]);
    }
}
