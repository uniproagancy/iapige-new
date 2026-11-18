<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\OrderEvent;
use App\Models\Supplier;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyPolicy;
use App\Models\WarrantySmsMessage;
use App\Services\Warranties\WarrantyApproval;
use App\Services\Warranties\WarrantyCertificateManager;
use App\Services\Warranties\WarrantyCourierSerial;
use App\Services\Warranties\WarrantyDocumentStore;
use App\Services\Warranties\WarrantyPolicyResolver;
use App\Services\Warranties\WarrantySms;
use App\Services\Warranties\WarrantySmsGateway;
use App\Services\Warranties\WarrantySnapshotBuilder;
use App\Services\Warranties\WarrantySupplierEditor;
use App\Services\Warranties\WarrantySupplierIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Embedded staff workflow. Reading an order never creates a delivery or a certificate. */
class OrderPanel extends Component
{
    use AuthorizesWarranties;
    use WithFileUploads;

    #[Locked]
    public int $orderId;

    #[Locked]
    public ?int $deliveryId = null;

    #[Locked]
    public array $itemIds = [];

    #[Locked]
    public ?int $editingCertificateId = null;

    #[Locked]
    public array $smsKeys = [];

    #[Locked]
    public array $smsResendKeys = [];

    #[Locked]
    public array $prepareSendKeys = [];

    #[Locked]
    public ?int $approvalCertificateId = null;

    #[Locked]
    public array $approvalMissingFields = [];

    #[Locked]
    public string $approvalSourceFingerprint = '';

    #[Locked]
    public string $formSourceFingerprint = '';

    public array $form = [];

    public array $approvalForm = [];

    public bool $approvalOpen = false;

    public array $policyChoices = [];

    public $completedOriginal;

    public string $notice = '';

    public string $selectedSupplierId = '';

    private const FIELDS = [
        'policy_id', 'actual_supplier_confirmed', 'manual_term_value', 'manual_term_unit',
        'serial_number', 'not_serialized_reason', 'sale_date', 'sale_date_override_reason',
        'delivery_date', 'delivery_date_confirmed', 'delivery_date_reason', 'manufacturer_date',
        'manufacturer_date_proof', 'supplier_native_sku', 'supplier_native_sku_reference', 'supplier_native_sku_reason',
    ];

    private const APPROVAL_FIELDS = [
        'service_name', 'service_address', 'service_phone', 'service_email', 'coverage', 'conditions',
        'claim_instructions', 'component_terms', 'start_basis', 'source_reference', 'requires_original',
        'approve_as_admin', 'review_note',
    ];

    public function mount(int $orderId, ?int $deliveryId = null, array $itemIds = []): void
    {
        $this->warrantyActor();
        $this->orderId = $orderId;
        $this->deliveryId = $deliveryId;
        $this->order();
        if ($deliveryId !== null) {
            OrderDelivery::where('order_id', $this->orderId)->visibleTo($this->warrantyActor())->findOrFail($deliveryId);
        }
        foreach ($itemIds as $id) {
            abort_unless(filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0, 404);
        }
        $this->itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($this->itemIds !== []) {
            abort_unless($this->items()->whereIn('id', $this->itemIds)->count() === count($this->itemIds), 404);
        }
        foreach ($this->certificateQuery()->pluck('id') as $id) {
            $this->smsKeys[$id] = (string) Str::uuid();
            $this->prepareSendKeys[$id] = (string) Str::uuid();
        }
    }

    public function open(int $itemId, int $unitIndex): void
    {
        $actor = $this->warrantyActor();
        $item = $this->items()->findOrFail($itemId);
        $input = [];
        $choice = $this->policyChoices[$itemId.'_'.$unitIndex] ?? null;
        if (filled($choice)) {
            $policy = $this->applicablePolicies($item)->firstWhere('id', (int) $choice);
            if (! $policy) {
                throw ValidationException::withMessages(['policy_id' => __('warranty.errors.approved_policy_required')]);
            }
            $input['policy_id'] = $policy->id;
        }
        $certificate = app(WarrantyCertificateManager::class)->prepare($item, $unitIndex, $actor, $input);
        if ($certificate->status === WarrantyCertificate::DRAFT) {
            $certificate = app(WarrantySupplierEditor::class)->refreshDraft($certificate, $actor);
        }
        $this->editingCertificateId = $certificate->id;
        $this->smsKeys[$certificate->id] ??= (string) Str::uuid();
        $this->completedOriginal = null;
        $this->notice = '';
        $this->resetValidation();
        $this->resetApproval();
        $this->loadForm($certificate);
    }

    public function close(): void
    {
        $this->warrantyActor();
        $this->editingCertificateId = null;
        $this->form = [];
        $this->formSourceFingerprint = '';
        $this->selectedSupplierId = '';
        $this->completedOriginal = null;
        $this->resetApproval();
        $this->resetValidation();
    }

    public function save(): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate();
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_not_draft')]);
        }
        $currentSupplierId = (int) $certificate->deliveryItem?->supplier_id;
        if ($currentSupplierId < 1 || (int) $certificate->supplier_id !== $currentSupplierId
            || (int) $this->selectedSupplierId !== $currentSupplierId) {
            throw ValidationException::withMessages(['selectedSupplierId' => __('warranty.errors.supplier_source_changed')]);
        }
        $input = array_intersect_key($this->form, array_flip(self::FIELDS));
        // The explicit save confirms the displayed real supplier; the editor rechecks its exact source under lock.
        $input['actual_supplier_confirmed'] = true;
        foreach (['policy_id', 'manual_term_value', 'manual_term_unit', 'sale_date', 'delivery_date', 'manufacturer_date'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }
        $certificate = app(WarrantySupplierEditor::class)->saveDraft($certificate, $input, $actor, $this->formSourceFingerprint);
        $this->loadForm($certificate);
        $this->notice = __('warranty.draft_saved');
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

    public function changeSupplier(): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate();
        abort_unless($certificate->status === WarrantyCertificate::DRAFT, 422);
        $input = $this->validate(['selectedSupplierId' => ['required', 'integer', 'min:1', 'exists:suppliers,id']], [],
            ['selectedSupplierId' => __('warranty.supplier')]);
        $certificate = app(WarrantySupplierEditor::class)->change($certificate, (int) $input['selectedSupplierId'], $actor,
            $this->formSourceFingerprint);
        $this->loadForm($certificate);
        $this->completedOriginal = null;
        $this->resetApproval();
        $this->resetValidation();
        $this->notice = 'მომწოდებელი შეიცვალა შეკვეთის მიწოდების ნივთზეც. გადაამოწმეთ მისი პროდუქტის კოდი და შეავსეთ სერიული ნომერი / IMEI.';
    }

    public function issue(): void
    {
        $actor = $this->warrantyActor();
        $this->save();
        $certificate = app(WarrantyCertificateManager::class)->issue($this->certificate(), $actor);
        $this->loadForm($certificate);
        $this->notice = __('warranty.certificate_issued');
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
        // This action keeps its original key after any outcome. A retry is a separate explicit resend.
        $key = $this->prepareSendKeys[$certificate->id] ??= (string) Str::uuid();
        abort_unless(Str::isUuid($key), 422);
        $message = app(WarrantySms::class)->send($certificate, $actor, $key);
        $this->notice = __('warranty.sms_result.'.$message->status);
    }

    public function releaseToCourier(int $certificateId): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate($certificateId);
        abort_unless($this->editingCertificateId === $certificate->id && $certificate->status === WarrantyCertificate::DRAFT, 422);
        $this->save();
        $certificate = $this->certificate($certificateId);
        $result = app(WarrantyCertificateManager::class)->releaseForCourier($certificate, $actor);
        if ($this->editingCertificateId === $certificate->id) {
            $this->loadForm($result['certificate']);
        }
        $this->notice = __('warranty.courier.serial_released');
    }

    public function reviewConditions(): void
    {
        $actor = $this->warrantyActor(true);
        $certificate = $this->certificate();
        abort_unless($certificate->status === WarrantyCertificate::DRAFT, 422);
        app(WarrantySupplierEditor::class)->assertSource($certificate, $this->formSourceFingerprint);
        $preview = app(WarrantyApproval::class)->preview($certificate, $actor);
        $this->approvalForm = array_intersect_key($preview, array_flip(self::APPROVAL_FIELDS));
        $this->approvalForm['approve_as_admin'] = false;
        $this->approvalForm['review_note'] = '';
        $this->approvalMissingFields = array_values(array_filter($preview['missing_fields'] ?? [], 'is_string'));
        $this->approvalSourceFingerprint = is_string($preview['source_fingerprint'] ?? null) ? $preview['source_fingerprint'] : '';
        $this->approvalCertificateId = $certificate->id;
        $this->approvalOpen = true;
        $this->notice = '';
        $this->resetValidation();
    }

    public function authorizeConditions(): void
    {
        $actor = $this->warrantyActor(true);
        $certificate = $this->certificate();
        abort_unless($this->approvalOpen && $this->approvalCertificateId === $certificate->id
            && $certificate->status === WarrantyCertificate::DRAFT, 422);
        app(WarrantySupplierEditor::class)->assertSource($certificate, $this->formSourceFingerprint);
        $input = array_intersect_key($this->approvalForm, array_flip(self::APPROVAL_FIELDS));
        $input['source_fingerprint'] = $this->approvalSourceFingerprint;
        $policy = app(WarrantyApproval::class)->authorize($certificate, $input, $actor);
        // Reviewing conditions must not erase the period/serial already typed in this same unit's form.
        $this->form['policy_id'] = $policy->id;
        $this->resetApproval();
        $this->resetValidation();
        $this->notice = 'პირობები დადასტურებულია. შეავსეთ ვადა და სერიული ნომერი, შემდეგ დაადასტურეთ ტალონი. SMS ცალკე ღილაკით იგზავნება.';
    }

    public function closeConditions(): void
    {
        $this->warrantyActor(true);
        $this->resetApproval();
        $this->resetValidation();
    }

    public function correct(int $certificateId): void
    {
        $actor = $this->warrantyActor(true);
        $certificate = app(WarrantyCertificateManager::class)->correct($this->certificate($certificateId), $actor);
        $this->editingCertificateId = $certificate->id;
        $this->smsKeys[$certificate->id] ??= (string) Str::uuid();
        $this->resetApproval();
        $this->loadForm($certificate);
        $this->notice = __('warranty.draft_saved');
    }

    public function uploadCompletedOriginal(): void
    {
        $actor = $this->warrantyActor();
        $this->validate(['completedOriginal' => ['required', 'file', 'mimes:pdf,docx', 'max:20480']]);
        app(WarrantyDocumentStore::class)->forCertificate($this->certificate(), $this->completedOriginal, 'completed_original', $actor);
        $this->completedOriginal = null;
        $this->notice = __('warranty.document_saved');
    }

    public function send(int $certificateId): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate($certificateId);
        $key = $this->smsKey($certificate->id);
        abort_unless(Str::isUuid($key), 422);
        $message = app(WarrantySms::class)->send($certificate, $actor, $key);
        if ($message->status === 'rejected' && $message->idempotency_key === $key) {
            // A confirmed rejection permits a new explicit attempt. Uncertain/accepted attempts keep their key.
            $this->smsKeys[$certificate->id] = (string) Str::uuid();
        }
        $this->notice = __('warranty.sms_result.'.$message->status);
    }

    public function resend(int $certificateId, int $previousAttemptId): void
    {
        $actor = $this->warrantyActor();
        $certificate = $this->certificate($certificateId);
        $key = $this->resendKey($certificate->id, $previousAttemptId);
        $message = app(WarrantySms::class)->resend($certificate, $actor, $key, $previousAttemptId);
        $this->notice = __('warranty.sms_result.'.$message->status);
    }

    protected function order(): Order
    {
        return Order::query()->findOrFail($this->orderId);
    }

    protected function items(): Builder
    {
        $actor = $this->warrantyActor();

        return OrderDeliveryItem::query()->where('is_active', true)
            ->when($this->deliveryId !== null, fn (Builder $query) => $query->where('order_delivery_id', $this->deliveryId))
            ->when($this->itemIds !== [], fn (Builder $query) => $query->whereIn('id', $this->itemIds))
            ->whereHas('delivery', fn (Builder $query) => $query->where('order_id', $this->orderId)->visibleTo($actor));
    }

    protected function certificate(?int $id = null): WarrantyCertificate
    {
        return $this->certificateQuery()->findOrFail($id ?? $this->editingCertificateId);
    }

    protected function certificateQuery(): Builder
    {
        $actor = $this->warrantyActor();

        return WarrantyCertificate::query()->where('order_id', $this->orderId)
            ->when($this->itemIds !== [], fn (Builder $query) => $query->whereIn('order_delivery_item_id', $this->itemIds))
            ->whereHas('deliveryItem', fn (Builder $query) => $query->where('is_active', true)->whereHas('delivery',
                fn (Builder $delivery) => $delivery->where('order_id', $this->orderId)
                    ->visibleTo($actor)
                    ->when($this->deliveryId !== null, fn (Builder $scope) => $scope->whereKey($this->deliveryId))));
    }

    protected function smsKey(int $certificateId, ?WarrantySmsMessage $attempt = null): string
    {
        $key = $this->smsKeys[$certificateId] ?? '';
        $attempt ??= WarrantySmsMessage::where('warranty_certificate_id', $certificateId)->latest('id')->first();
        if (! Str::isUuid($key) || ($attempt?->status === 'rejected' && $attempt->idempotency_key === $key)) {
            $this->smsKeys[$certificateId] = (string) Str::uuid();
        }

        return $this->smsKeys[$certificateId];
    }

    protected function resendKey(int $certificateId, int $previousAttemptId): string
    {
        abort_unless($previousAttemptId > 0, 422);
        $slot = $certificateId.'_'.$previousAttemptId;
        if (! isset($this->smsResendKeys[$slot]) || ! Str::isUuid($this->smsResendKeys[$slot])) {
            $this->smsResendKeys[$slot] = (string) Str::uuid();
        }

        return $this->smsResendKeys[$slot];
    }

    protected function loadForm(WarrantyCertificate $certificate): void
    {
        $this->form = [];
        foreach (self::FIELDS as $field) {
            $value = $certificate->getAttribute($field);
            $this->form[$field] = in_array($field, ['sale_date', 'delivery_date', 'manufacturer_date'], true)
                ? ($value?->toDateString() ?? '') : ($value ?? '');
        }
        $this->formSourceFingerprint = app(WarrantySupplierEditor::class)->sourceFingerprint($certificate);
        $this->selectedSupplierId = $certificate->supplier_id ? (string) $certificate->supplier_id : '';
        $this->prepareSendKeys[$certificate->id] ??= (string) Str::uuid();
    }

    protected function resetApproval(): void
    {
        $this->approvalCertificateId = null;
        $this->approvalMissingFields = [];
        $this->approvalSourceFingerprint = '';
        $this->approvalForm = [];
        $this->approvalOpen = false;
    }

    protected function applicablePolicies(OrderDeliveryItem $item, ?WarrantyCertificate $certificate = null): Collection
    {
        $date = $certificate?->sale_date?->toDateString();
        if (! $date && $item->delivery?->order?->status === Order::COMPLETED) {
            $date = OrderEvent::where('order_id', $this->orderId)->where('type', 'status')->where('to', Order::COMPLETED)
                ->orderByDesc('id')->first()?->created_at?->toDateString();
        }
        if (! $date || ! $item->supplier_id) {
            return collect();
        }

        return WarrantyPolicy::where('supplier_id', $item->supplier_id)->where('status', WarrantyPolicy::APPROVED)
            ->orderByDesc('version')->get()->filter(fn ($policy) => app(WarrantyPolicyResolver::class)->matches($policy, $item, $date))->values();
    }

    public function render()
    {
        $actor = $this->warrantyActor();
        $order = $this->order();
        $items = $this->items()->with(['supplier', 'product.brand', 'delivery.order'])->orderBy('id')->get();
        $certificates = $this->certificateQuery()->orderByDesc('revision')->get()
            ->unique(fn ($certificate) => $certificate->order_delivery_item_id.'_'.$certificate->unit_index)
            ->keyBy(fn ($certificate) => $certificate->order_delivery_item_id.'_'.$certificate->unit_index);
        $attempts = WarrantySmsMessage::whereIn('warranty_certificate_id', $certificates->pluck('id'))
            ->orderByDesc('id')->get()
            ->groupBy('warranty_certificate_id');
        $sendingEnabled = (bool) config('warranty.sms_enabled', false) && (bool) config('warranty.public_enabled', false);
        $gatewayReady = app(WarrantySmsGateway::class)->ready();
        $cards = [];
        foreach ($items as $item) {
            for ($unit = 1; $unit <= (int) $item->qty; $unit++) {
                $certificate = $certificates->get($item->id.'_'.$unit);
                $messages = $certificate ? $attempts->get($certificate->id, collect()) : collect();
                $latestAttempt = $messages->first();
                if ($certificate) {
                    $this->smsKey($certificate->id, $latestAttempt);
                    if ($latestAttempt) {
                        $this->resendKey($certificate->id, $latestAttempt->id);
                    }
                }
                $resendState = app(WarrantySms::class)->resendState($latestAttempt);
                $blockedAttempt = $messages->contains(fn ($message) => in_array($message->status, ['pending', 'sending', 'accepted', 'unknown'], true));
                $policies = $this->applicablePolicies($item, $certificate);
                $policyStale = $certificate?->status === WarrantyCertificate::DRAFT && filled($certificate->policy_id)
                    && ! $policies->contains(fn ($policy) => (int) $policy->id === (int) $certificate->policy_id);
                $cards[] = ['item' => $item, 'unit' => $unit, 'certificate' => $certificate,
                    'native' => $certificate && $certificate->status !== WarrantyCertificate::DRAFT
                        ? data_get($certificate->snapshot, 'item.supplier_sku')
                        : app(WarrantySupplierIdentity::class)->resolve($item, $certificate)['native_sku'],
                    'policies' => $policies,
                    'policyStale' => $policyStale,
                    'releaseState' => $certificate?->status === WarrantyCertificate::DRAFT
                        ? app(WarrantyCourierSerial::class)->releaseState($certificate)
                        : ['ready' => false, 'release_hash' => null, 'reason' => null],
                    'autoReady' => $certificate?->status === WarrantyCertificate::DRAFT && ! $policyStale && $policies->isEmpty()
                        && app(WarrantyApproval::class)->canAutoApprove($certificate),
                    'smsStatus' => $latestAttempt?->status,
                    'smsAttemptId' => $latestAttempt?->id,
                    'resendState' => $resendState,
                    'canResend' => $sendingEnabled && $gatewayReady && $certificate?->status === WarrantyCertificate::ISSUED
                        && $resendState['canResend'],
                    'canSend' => $sendingEnabled && $gatewayReady && ! $blockedAttempt
                        && $certificate?->status === WarrantyCertificate::ISSUED];
            }
        }
        $editing = $this->editingCertificateId ? $this->certificate()->load(['policy', 'documents']) : null;
        $sourceStale = $editing?->status === WarrantyCertificate::DRAFT
            && ! hash_equals($this->formSourceFingerprint, app(WarrantySupplierEditor::class)->sourceFingerprint($editing));
        $canChangeSupplier = $editing?->status === WarrantyCertificate::DRAFT && ($actor->isAdmin()
            || ($editing->deliveryItem?->delivery?->status === OrderDelivery::ACTIVE
                && ! WarrantyCertificate::where('order_delivery_item_id', $editing->order_delivery_item_id)
                    ->whereIn('status', [WarrantyCertificate::ISSUED, WarrantyCertificate::REVOKED])->exists()));

        return view('livewire.admin.warranties.order-panel', [
            'order' => $order, 'cards' => $cards, 'editing' => $editing,
            'snapshot' => $editing ? app(WarrantySnapshotBuilder::class)->draft($editing) : [],
            'conditionsPreview' => $editing ? app(WarrantyApproval::class)->summary($editing, $actor) : null,
            'isOwner' => $actor->isAdmin(),
            'smsEnabled' => $sendingEnabled, 'gatewayReady' => $gatewayReady,
            'sourceStale' => $sourceStale, 'canChangeSupplier' => $canChangeSupplier,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }
}
