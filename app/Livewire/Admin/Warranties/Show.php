<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyPolicy;
use App\Services\Warranties\WarrantyCertificateManager;
use App\Services\Warranties\WarrantyApproval;
use App\Services\Warranties\WarrantyDocumentStore;
use App\Services\Warranties\WarrantySnapshotBuilder;
use App\Services\Warranties\WarrantySms;
use App\Services\Warranties\WarrantySmsGateway;
use App\Services\Warranties\WarrantySupplierEditor;
use App\Models\WarrantySmsMessage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Show extends Component
{
    use AuthorizesWarranties;
    use WithFileUploads;

    #[Locked]
    public int $certificateId;

    #[Locked]
    public string $smsIdempotencyKey;

    #[Locked]
    public array $smsResendKeys = [];

    #[Locked]
    public string $formSourceFingerprint = '';

    public array $form = [];

    public string $revocationReason = '';

    public $completedOriginal;

    protected const FIELDS = [
        'policy_id', 'actual_supplier_confirmed', 'serial_number', 'not_serialized_reason',
        'sale_date', 'sale_date_override_reason', 'delivery_date', 'manufacturer_date', 'delivery_date_confirmed',
        'delivery_date_reason', 'manufacturer_date_proof',
        'manual_term_value', 'manual_term_unit',
        'supplier_native_sku', 'supplier_native_sku_reference', 'supplier_native_sku_reason',
    ];

    public function mount(WarrantyCertificate $certificate): void
    {
        $this->warrantyActor();
        $this->certificateId = $certificate->id;
        $previousKey = old('idempotency_key');
        $this->smsIdempotencyKey = is_string($previousKey) && Str::isUuid($previousKey)
            ? $previousKey : (string) Str::uuid();
        $this->loadForm();
    }

    protected function certificate(): WarrantyCertificate
    {
        return WarrantyCertificate::query()->findOrFail($this->certificateId);
    }

    protected function loadForm(): void
    {
        $certificate = $this->certificate();
        foreach (self::FIELDS as $field) {
            $value = $certificate->getAttribute($field);
            if (in_array($field, ['sale_date', 'delivery_date', 'manufacturer_date'], true)) {
                $value = $value?->format('Y-m-d');
            }
            $this->form[$field] = $value ?? '';
        }
        $this->formSourceFingerprint = app(WarrantySupplierEditor::class)->sourceFingerprint($certificate);
    }

    public function save(): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate();
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_not_draft')]);
        }
        $currentSupplierId = (int) $certificate->deliveryItem?->supplier_id;
        if ($currentSupplierId < 1 || (int) $certificate->supplier_id !== $currentSupplierId) {
            throw ValidationException::withMessages(['supplier_id' => __('warranty.errors.supplier_source_changed')]);
        }
        $input = array_intersect_key($this->form, array_flip(self::FIELDS));
        // This applies only through the editor's atomic, fingerprint-checked save for the displayed actual item.
        $input['actual_supplier_confirmed'] = true;
        $input['policy_id'] = ($input['policy_id'] ?? '') === '' ? null : $input['policy_id'];
        foreach (['sale_date', 'delivery_date', 'manufacturer_date'] as $field) {
            $input[$field] = ($input[$field] ?? '') === '' ? null : $input[$field];
        }
        foreach (['manual_term_value', 'manual_term_unit'] as $field) {
            $input[$field] = ($input[$field] ?? '') === '' ? null : $input[$field];
        }
        app(WarrantySupplierEditor::class)->saveDraft($certificate, $input, $actor, $this->formSourceFingerprint);
        $this->loadForm();
        $this->dispatch('toast', message: __('warranty.draft_saved'));
    }

    public function updatingForm(mixed $value, ?string $key = null): void
    {
        $this->warrantyActor();
        if ($key === 'supplier_native_sku'
            && trim((string) $value) !== trim((string) ($this->form['supplier_native_sku'] ?? ''))) {
            $this->form['supplier_native_sku_reference'] = '';
            $this->form['supplier_native_sku_reason'] = '';
        }
    }

    public function issue(): void
    {
        $actor = $this->warrantyActor();
        $this->save();
        app(WarrantyCertificateManager::class)->issue($this->certificate(), $actor);
        $this->loadForm();
        $this->dispatch('toast', message: __('warranty.certificate_issued'));
    }

    public function prepareAndSend(): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate();
        if (! config('warranty.sms_enabled') || ! config('warranty.public_enabled')) {
            throw ValidationException::withMessages(['sms' => __('warranty.errors.sms_disabled')]);
        }
        if (! app(WarrantySmsGateway::class)->ready()) {
            throw ValidationException::withMessages(['sms' => __('warranty.errors.sms_gateway_disabled')]);
        }
        if ($certificate->status === WarrantyCertificate::DRAFT) {
            $this->issue();
            $certificate = $this->certificate();
        }
        abort_unless(Str::isUuid($this->smsIdempotencyKey), 422);
        $message = app(WarrantySms::class)->send($certificate, $actor, $this->smsIdempotencyKey);
        $this->dispatch('toast', message: __('warranty.sms_result.'.$message->status));
    }

    public function uploadCompletedOriginal(): void
    {
        $actor = $this->warrantyActor();
        $this->validate([
            'completedOriginal' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
        ], [], ['completedOriginal' => __('warranty.completed_original')]);
        app(WarrantyDocumentStore::class)->forCertificate($this->certificate(), $this->completedOriginal, 'completed_original', $actor);
        $this->completedOriginal = null;
        $this->dispatch('toast', message: __('warranty.document_saved'));
    }

    public function correct(): void
    {
        $actor = $this->warrantyActor(true);
        $draft = app(WarrantyCertificateManager::class)->correct($this->certificate(), $actor);
        $this->redirectRoute('admin.warranties.show', ['certificate' => $draft->id], navigate: true);
    }

    public function revoke(): void
    {
        $actor = $this->warrantyActor(true);
        $this->validate(['revocationReason' => ['required', 'string', 'min:5', 'max:1000']], [], [
            'revocationReason' => __('warranty.revocation_reason'),
        ]);
        app(WarrantyCertificateManager::class)->revoke($this->certificate(), trim($this->revocationReason), $actor);
        $this->revocationReason = '';
        $this->loadForm();
        $this->dispatch('toast', message: __('warranty.certificate_revoked'));
    }

    public function render()
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate()->load(['policy', 'supplier', 'deliveryItem.delivery', 'order', 'documents', 'events.actor']);
        $policies = WarrantyPolicy::query()->where('supplier_id', $certificate->supplier_id)
            ->where('status', 'approved')->orderByDesc('version')->get();
        $selectedPolicy = $policies->firstWhere('id', (int) ($this->form['policy_id'] ?? 0));
        $blankForms = $selectedPolicy?->documents()->where('role', 'official_form')->get() ?? collect();
        $snapshot = $certificate->status === 'draft'
            ? app(WarrantySnapshotBuilder::class)->draft($certificate)
            : ($certificate->snapshot ?? []);

        $smsAttempt = WarrantySmsMessage::query()->where('warranty_certificate_id', $certificate->id)->latest('id')->first();
        if ($smsAttempt) {
            $this->smsResendKeys[$smsAttempt->id] ??= (string) Str::uuid();
        }
        $gatewayReady = app(WarrantySmsGateway::class)->ready();

        return view('livewire.admin.warranties.show', [
            'smsAttempt' => $smsAttempt,
            'smsResendState' => app(WarrantySms::class)->resendState($smsAttempt),
            'smsActionKey' => $smsAttempt ? $this->smsResendKeys[$smsAttempt->id] : $this->smsIdempotencyKey,
            'certificate' => $certificate,
            'policies' => $policies,
            'blankForms' => $blankForms,
            'snapshot' => $snapshot,
            'conditionsPreview' => app(WarrantyApproval::class)->summary($certificate, $actor),
            'supplierIdentity' => app(\App\Services\Warranties\WarrantySupplierIdentity::class)->resolve($certificate->deliveryItem, $certificate),
            'isOwner' => $actor->isAdmin(),
            'isDraft' => $certificate->status === 'draft',
            'smsEnabled' => (bool) config('warranty.sms_enabled', false)
                && (bool) config('warranty.public_enabled', false) && $gatewayReady,
            'gatewayReady' => $gatewayReady,
            'sourceStale' => $certificate->status === WarrantyCertificate::DRAFT
                && ! hash_equals($this->formSourceFingerprint, app(WarrantySupplierEditor::class)->sourceFingerprint($certificate)),
        ])->layout('layouts.admin', ['title' => __('warranty.certificate_title', ['number' => $certificate->number])]);
    }
}
