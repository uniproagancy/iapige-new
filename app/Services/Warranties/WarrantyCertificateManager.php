<?php

namespace App\Services\Warranties;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\OrderEvent;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyEvent;
use App\Models\WarrantyPolicy;
use App\Support\Phone;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyCertificateManager
{
    use DetectsConcurrencyErrors;

    public function __construct(private WarrantyPolicyResolver $resolver, private WarrantySnapshotBuilder $snapshots,
        private WarrantyEventRecorder $events, private WarrantyDocumentStore $documents) {}

    public function prepare(OrderDeliveryItem $item, int $unitIndex, User $actor, array $input = []): WarrantyCertificate
    {
        $this->staff($actor);

        return DB::transaction(function () use ($item, $unitIndex, $actor, $input): WarrantyCertificate {
            $item = OrderDeliveryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->source($item, $unitIndex);
            $existing = WarrantyCertificate::where('order_delivery_item_id', $item->id)->where('unit_index', $unitIndex)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $certificate = new WarrantyCertificate(['number' => 'IAPI-W-'.Str::ulid(), 'revision' => 1,
                'status' => WarrantyCertificate::DRAFT, 'order_delivery_item_id' => $item->id, 'unit_index' => $unitIndex,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->refreshSource($certificate, $item, $actor);
            $this->applyInput($certificate, $input);
            if (! $certificate->policy_id && $certificate->sale_date) {
                $certificate->policy_id = $this->resolver->resolve($item, $certificate->sale_date->toDateString())?->id;
            }
            $certificate->save();
            $this->events->record('certificate_prepared', $actor, $certificate);
            $this->saleDateEvent($certificate, $actor, []);
            $this->nativeSkuEvent($certificate, $actor, []);

            return $certificate->fresh();
        });
    }

    public function saveDraft(WarrantyCertificate $certificate, array $input, User $actor, bool $requireNativeSkuProof = false): WarrantyCertificate
    {
        $this->staff($actor);

        return DB::transaction(function () use ($certificate, $input, $actor, $requireNativeSkuProof): WarrantyCertificate {
            $item = $this->lockedItem($certificate);
            $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            $this->draft($certificate);
            $this->source($item, $certificate->unit_index);
            $previousDate = $this->saleDateState($certificate);
            $previousNative = $this->nativeSkuState($certificate);
            $currentIdentity = app(WarrantySupplierIdentity::class)->identityHash($item);
            $staleNative = filled($certificate->supplier_native_sku) && filled($certificate->supplier_native_sku_identity_sha256)
                && (! $currentIdentity || ! hash_equals($certificate->supplier_native_sku_identity_sha256, $currentIdentity));
            $sourceChanged = $this->sourceChanged($certificate, $item);
            $this->refreshSource($certificate, $item, $actor);
            if ($sourceChanged) {
                // A stale form cannot reconfirm the former supplier or physical unit under a different source.
                $input = array_diff_key($input, array_flip(['policy_id', 'actual_supplier_confirmed', 'serial_number',
                    'not_serialized_reason', 'supplier_native_sku', 'supplier_native_sku_reference', 'supplier_native_sku_reason']));
            }
            if ($staleNative) {
                // Do not let a stale form silently reconfirm the previous supplier's code under a new source.
                $input = array_diff_key($input, array_flip(['supplier_native_sku', 'supplier_native_sku_reference', 'supplier_native_sku_reason']));
            }
            $this->applyInput($certificate, $input, $requireNativeSkuProof);
            $certificate->updated_by = $actor->id;
            $certificate->save();
            $this->events->record('certificate_draft_updated', $actor, $certificate);
            $this->saleDateEvent($certificate, $actor, $previousDate);
            $this->nativeSkuEvent($certificate, $actor, $previousNative);

            return $certificate->fresh();
        });
    }

    public function issue(WarrantyCertificate $certificate, User $actor): WarrantyCertificate
    {
        $this->staff($actor);
        $writtenPath = null;
        try {
            return DB::transaction(function () use ($certificate, $actor, &$writtenPath): WarrantyCertificate {
                $item = $this->lockedItem($certificate);
                $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
                if ($certificate->status === WarrantyCertificate::ISSUED) {
                    app(WarrantyCertificateFiles::class)->path($certificate);

                    return $certificate;
                }
                [$policy, $previousNative] = $this->validateIssuance($certificate, $item, $actor);

                return $this->writeIssued($certificate, $item, $actor, $policy, $previousNative, $writtenPath);
            });
        } catch (\Throwable $error) {
            if ($writtenPath !== null) {
                Storage::disk(config('warranty.disk', 'local'))->delete($writtenPath);
            }
            throw $error;
        }
    }

    /** Staff explicitly approves every material fact while leaving the physical unit number for pickup. */
    public function releaseForCourier(WarrantyCertificate $certificate, User $actor): array
    {
        $this->staff($actor);

        return $this->courierSerialTransaction(function (?string &$writtenPath) use ($certificate, $actor): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->staff($actor);
            $item = $this->lockedItem($certificate);
            $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            $this->draft($certificate);
            $this->emptyCourierSerial($certificate);
            $this->latestUnitRevision($certificate);
            if ($certificate->previous_certificate_id) {
                $this->owner($actor);
            }
            [$policy, $previousNative] = $this->validateIssuance($certificate, $item, $actor, true, false);
            $certificate->forceFill(['policy_id' => $policy->id, 'updated_by' => $actor->id])->save();
            $certificate->setRelation('policy', $policy->load('documents'))->setRelation('deliveryItem', $item);
            $this->validateSeller($this->snapshots->build($certificate));
            $this->nativeSkuEvent($certificate, $actor, $previousNative);
            $hash = $this->courierSerialFingerprint($certificate, $item);
            $this->events->record('certificate_courier_serial_released', $actor, $certificate, $policy,
                ['reference' => $hash, 'delivery_id' => $item->order_delivery_id]);

            return ['certificate' => $certificate->fresh(), 'release_hash' => $hash];
        });
    }

    /** Advisory only: the final POST repeats all checks under row locks. No approval or document writes. */
    public function courierSerialReleaseState(WarrantyCertificate $certificate): array
    {
        try {
            $certificate = WarrantyCertificate::findOrFail($certificate->id);
            if ($certificate->status !== WarrantyCertificate::DRAFT) {
                return ['ready' => false, 'release_hash' => null, 'reason' => 'certificate_not_draft'];
            }
            $this->emptyCourierSerial($certificate);
            $release = $this->courierSerialRelease($certificate);
            if (! $release) {
                return ['ready' => false, 'release_hash' => null, 'reason' => 'courier_serial_release_required'];
            }
            $this->releaseActor($certificate, $release);
            $this->latestUnitRevision($certificate);
            $item = $certificate->deliveryItem;
            if (! $item) {
                $this->invalid('certificate', 'courier_serial_release_stale');
            }
            $this->source($item, $certificate->unit_index);
            $hash = $this->courierSerialFingerprint($certificate, $item);
            if (! is_string($release->metadata['reference'] ?? null) || ! hash_equals($release->metadata['reference'], $hash)) {
                $this->invalid('certificate', 'courier_serial_release_stale');
            }

            return ['ready' => true, 'release_hash' => $hash, 'reason' => null];
        } catch (\Throwable) {
            return ['ready' => false, 'release_hash' => null, 'reason' => 'courier_serial_release_stale'];
        }
    }

    /** Narrow delegated action: the courier contributes only a serial to a staff-released draft. */
    public function finalizeCourierSerial(WarrantyCertificate $certificate, User $actor, int $deliveryId,
        string $serialNumber, string $expectedReleaseHash): WarrantyCertificate
    {
        abort_unless($actor->exists && $actor->isCourier(), 403);
        $input = Validator::make(['serial_number' => $serialNumber, 'release_hash' => $expectedReleaseHash], [
            'serial_number' => ['required', 'string', 'max:191', 'regex:/\A[^\x00-\x1f\x7f]+\z/u'],
            'release_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ])->validate();
        $input['serial_number'] = trim($input['serial_number']);

        return $this->courierSerialTransaction(function (?string &$writtenPath) use ($certificate, $actor, $deliveryId, $input): WarrantyCertificate {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isCourier(), 403);
            $item = $this->lockedItem($certificate);
            $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            $delivery = app(WarrantyCourierAccess::class)->delivery($actor, $deliveryId, true);
            abort_unless((int) $item->order_delivery_id === (int) $delivery->id
                && (int) $certificate->order_id === (int) $delivery->order_id && $item->is_active, 404);
            $item->setRelation('delivery', $delivery->load('order'));
            $this->source($item, $certificate->unit_index);
            $this->latestUnitRevision($certificate);
            $release = $this->courierSerialRelease($certificate);
            if (! $release || ! is_string($release->metadata['reference'] ?? null)
                || ! hash_equals($release->metadata['reference'], $input['release_hash'])) {
                $this->invalid('certificate', 'courier_serial_release_required');
            }
            $releaseActor = $this->releaseActor($certificate, $release, true);
            if ($certificate->status === WarrantyCertificate::ISSUED && $certificate->revoked_at === null) {
                $completed = $certificate->events()->where('type', 'certificate_courier_serial_completed')
                    ->where('metadata->reference', $input['release_hash'])->exists();
                if (! $completed || $certificate->serial_number !== $input['serial_number']) {
                    $this->invalid('serial_number', 'courier_serial_changed');
                }
                app(WarrantyCertificateFiles::class)->path($certificate);

                return $certificate;
            }
            $this->draft($certificate);
            $this->emptyCourierSerial($certificate);
            if (! hash_equals($input['release_hash'], $this->courierSerialFingerprint($certificate, $item))) {
                $this->invalid('certificate', 'courier_serial_release_stale');
            }
            // No directory approval is permitted on this delegated courier path.
            [$policy, $previousNative] = $this->validateIssuance($certificate, $item, $actor, false, false);
            if (! hash_equals($input['release_hash'], $this->courierSerialFingerprint($certificate, $item))) {
                $this->invalid('certificate', 'courier_serial_release_stale');
            }
            $certificate->serial_number = $input['serial_number'];
            $this->validateUnit($certificate, $item);
            $issued = $this->writeIssued($certificate, $item, $actor, $policy, $previousNative, $writtenPath, $releaseActor);
            $this->events->record('certificate_courier_serial_completed', $actor, $issued, $policy,
                ['reference' => $input['release_hash'], 'delivery_id' => $deliveryId, 'previous_id' => $release->id]);

            return $issued;
        });
    }

    /** A failed attempt owns only its newly written private PDF; remove it before any bounded retry. */
    private function courierSerialTransaction(callable $callback): mixed
    {
        $writtenPath = null;
        try {
            return DB::transaction(function () use ($callback, &$writtenPath): mixed {
                if ($writtenPath !== null) {
                    Storage::disk(config('warranty.disk', 'local'))->delete($writtenPath);
                    $writtenPath = null;
                }

                return $callback($writtenPath);
            }, 3);
        } catch (\Throwable $error) {
            if ($writtenPath !== null) {
                Storage::disk(config('warranty.disk', 'local'))->delete($writtenPath);
            }
            if ($this->causedByConcurrencyError($error)) {
                $this->invalid('certificate', 'courier_serial_release_stale');
            }
            throw $error;
        }
    }

    private function validateIssuance(WarrantyCertificate $certificate, OrderDeliveryItem $item, User $actor,
        bool $allowDirectoryApproval = true, bool $requireUnit = true): array
    {
        $this->draft($certificate);
        $this->source($item, $certificate->unit_index);
        if ($this->sourceChanged($certificate, $item)) {
            $this->invalid('certificate', 'supplier_source_changed');
        }
        if ($item->delivery->order->status !== Order::COMPLETED) {
            $this->invalid('certificate', 'order_completion_required');
        }
        if ($certificate->manual_term_value < 1 || ! in_array($certificate->manual_term_unit, ['days', 'months', 'years'], true)) {
            $this->invalid('manual_term_value', 'manual_period_required');
        }
        if (! $certificate->actual_supplier_confirmed || ! $item->supplier_id
            || (int) $certificate->supplier_id !== (int) $item->supplier_id) {
            $this->invalid('actual_supplier_confirmed', 'supplier_confirmation_required');
        }
        $this->refreshSaleDate($certificate, $item);
        $previousNative = $this->nativeSkuState($certificate);
        $this->refreshNativeSku($certificate, $item);
        $this->validateDates($certificate, $item, 'sale_date');
        $boundPolicy = $certificate->policy;
        if ($allowDirectoryApproval && $boundPolicy
            && in_array($boundPolicy->approval_mode, ['verified_directory', 'supplier_profile'], true)
            && ! app(WarrantyApproval::class)->matchesBoundPolicy($boundPolicy, $item)) {
            // A central supplier edit applies to the next explicit issuance, never to a frozen PDF.
            $certificate->policy_id = null;
            $certificate->unsetRelation('policy');
        }
        $policy = $this->resolver->resolve($item, $certificate->sale_date->toDateString());
        if ($allowDirectoryApproval && ! $policy && ! $certificate->policy_id) {
            $policy = app(WarrantyApproval::class)->fromConfirmedDirectory($certificate, $actor);
        }
        if (! $policy || ($certificate->policy_id && (int) $certificate->policy_id !== (int) $policy->id)) {
            $this->invalid('policy_id', 'approved_policy_required');
        }
        $policy = WarrantyPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
        if ($policy->status !== WarrantyPolicy::APPROVED) {
            $this->invalid('policy_id', 'approved_policy_required');
        }
        $certificate->setRelation('policy', $policy->load('documents'))->setRelation('deliveryItem', $item);
        $this->validateDates($certificate, $item, $policy->start_basis);
        if ($policy->start_basis === 'manufacturer_date'
            && (! $certificate->manufacturer_date || ! filled($certificate->manufacturer_date_proof))) {
            $this->invalid('manufacturer_date_proof', 'manufacturer_proof_required');
        }
        if ($requireUnit) {
            $this->validateUnit($certificate, $item);
        }
        $this->refreshCustomer($certificate, $item);
        if (! filled($certificate->customer_name) || ! Phone::digits($certificate->customer_phone)) {
            $this->invalid('customer', 'customer_required');
        }
        $completed = $certificate->documents()->where('role', 'completed_original')->get();
        if ($policy->requires_original && $completed->isEmpty()) {
            $this->invalid('document', 'completed_original_required');
        }
        foreach ($completed->concat($policy->documents()->where('role', 'terms')->get()) as $document) {
            $this->documents->path($document);
        }

        return [$policy, $previousNative];
    }

    private function writeIssued(WarrantyCertificate $certificate, OrderDeliveryItem $item, User $actor, WarrantyPolicy $policy,
        array $previousNative, ?string &$writtenPath, ?User $replacementAuthorizer = null): WarrantyCertificate
    {
        $certificate->forceFill(['policy_id' => $policy->id, 'status' => WarrantyCertificate::ISSUED,
            'issued_at' => now(), 'issued_by' => $actor->id, 'updated_by' => $actor->id]);
        $certificate->setRelation('policy', $policy->load('documents'))->setRelation('deliveryItem', $item);
        $snapshot = $this->snapshots->build($certificate);
        $this->validateSeller($snapshot);
        $bytes = app(WarrantyPdf::class)->bytes($snapshot);
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 20 * 1024 * 1024) {
            $this->invalid('certificate', 'pdf_invalid');
        }
        $path = 'warranties/certificates/'.Str::ulid().'.pdf';
        app(WarrantyPrivateStorage::class)->put($path, $bytes);
        $writtenPath = $path;
        @chmod(Storage::disk(config('warranty.disk', 'local'))->path($path), 0600);
        $certificate->forceFill(['snapshot' => $snapshot, 'snapshot_sha256' => $this->snapshots->hash($snapshot),
            'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $bytes)])->save();
        app(WarrantyCertificateFiles::class)->path($certificate);
        if ($certificate->previous_certificate_id) {
            $previous = WarrantyCertificate::whereKey($certificate->previous_certificate_id)->lockForUpdate()->firstOrFail();
            if ($previous->status === WarrantyCertificate::ISSUED) {
                $this->owner($replacementAuthorizer ?? $actor);
                $previous->forceFill(['status' => WarrantyCertificate::REVOKED, 'revoked_at' => now(), 'revoked_by' => $actor->id,
                    'revocation_reason' => __('warranty.correction_replaced'), 'updated_by' => $actor->id])->save();
                $this->events->record('certificate_replaced', $actor, $previous, metadata: ['reference' => $certificate->id]);
            }
        }
        $this->events->record('certificate_issued', $actor, $certificate);
        $this->nativeSkuEvent($certificate, $actor, $previousNative);

        return $certificate->fresh();
    }

    private function validateSeller(array $snapshot): void
    {
        if (($snapshot['seller']['tax_id'] ?? null) !== '416353635'
            || array_filter(['name', 'address', 'phone', 'email'], fn ($key) => ! filled($snapshot['seller'][$key] ?? null))) {
            $this->invalid('seller', 'seller_identity_required');
        }
    }

    private function emptyCourierSerial(WarrantyCertificate $certificate): void
    {
        if (filled($certificate->serial_number) || filled($certificate->not_serialized_reason)) {
            $this->invalid('serial_number', 'courier_serial_not_empty');
        }
    }

    private function latestUnitRevision(WarrantyCertificate $certificate): void
    {
        $latest = WarrantyCertificate::where('order_delivery_item_id', $certificate->order_delivery_item_id)
            ->where('unit_index', $certificate->unit_index)->orderByDesc('revision')->orderByDesc('id')->first(['id']);
        if (! $latest || (int) $latest->id !== (int) $certificate->id) {
            $this->invalid('certificate', 'latest_revision_required');
        }
    }

    private function courierSerialRelease(WarrantyCertificate $certificate): ?WarrantyEvent
    {
        return $certificate->events()->where('type', 'certificate_courier_serial_released')->orderByDesc('id')->first();
    }

    private function releaseActor(WarrantyCertificate $certificate, WarrantyEvent $release, bool $lock = false): User
    {
        $actor = User::whereKey($release->actor_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $actor || ! $actor->isStaff() || ($certificate->previous_certificate_id && ! $actor->isAdmin())) {
            $this->invalid('certificate', 'courier_serial_release_stale');
        }

        return $actor;
    }

    private function courierSerialFingerprint(WarrantyCertificate $certificate, OrderDeliveryItem $item): string
    {
        $item->loadMissing(['supplier', 'product.brand', 'product.category', 'delivery.order']);
        $certificate->loadMissing('policy.documents');
        $documents = $certificate->documents()->orderBy('id')->get()->concat($certificate->policy?->documents ?? collect());
        $documentFacts = [];
        foreach ($documents as $document) {
            $this->documents->path($document);
            $documentFacts[] = $document->attributesToArray();
        }
        $previous = $certificate->previous_certificate_id ? WarrantyCertificate::find($certificate->previous_certificate_id) : null;

        return $this->snapshots->hash([
            'certificate' => $certificate->only(['id', 'number', 'revision', 'previous_certificate_id', 'order_id', 'order_item_id',
                'order_delivery_item_id', 'product_id', 'supplier_id', 'policy_id', 'unit_index', 'serial_number', 'not_serialized_reason',
                'sale_date', 'delivery_date', 'manufacturer_date', 'manufacturer_date_proof', 'delivery_date_confirmed', 'delivery_date_reason',
                'actual_supplier_confirmed', 'manual_term_value', 'manual_term_unit', 'sale_date_source', 'sale_date_source_event_id',
                'sale_date_override_reason', 'supplier_native_sku', 'supplier_native_sku_source', 'supplier_native_sku_reference',
                'supplier_native_sku_reason', 'supplier_native_sku_identity_sha256', 'customer_name', 'customer_phone', 'created_by', 'updated_by']),
            'source_identity' => app(WarrantyApproval::class)->identityFingerprint($item),
            'source_product' => [$item->product?->sku, $item->product?->name, $item->product?->deleted_at],
            'native_identity' => app(WarrantySupplierIdentity::class)->resolve($item, $certificate),
            'unit_quantity' => [$item->qty, $item->is_active],
            'delivery' => [$item->delivery?->id, $item->delivery?->status, $item->delivery?->is_eligible, $item->delivery?->delivered_at],
            'order' => [$item->delivery?->order?->id, $item->delivery?->order?->status, $item->delivery?->order?->deleted_at,
                $item->delivery?->order?->name, Phone::dial($item->delivery?->order?->phone)],
            'policy' => $certificate->policy?->attributesToArray(), 'documents' => $documentFacts,
            'previous' => $previous?->only(['id', 'status', 'revoked_at', 'snapshot_sha256', 'pdf_sha256']),
            'audit_watermark' => $certificate->events()->where('type', '!=', 'certificate_courier_serial_released')->max('id'),
            'snapshot' => $this->snapshots->build($certificate),
        ]);
    }

    public function revoke(WarrantyCertificate $certificate, string $reason, User $actor): WarrantyCertificate
    {
        $this->owner($actor);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        return DB::transaction(function () use ($certificate, $reason, $actor): WarrantyCertificate {
            $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            if ($certificate->status === WarrantyCertificate::REVOKED) {
                return $certificate;
            }
            if ($certificate->status !== WarrantyCertificate::ISSUED) {
                $this->invalid('certificate', 'certificate_not_issued');
            }
            $certificate->forceFill(['status' => WarrantyCertificate::REVOKED, 'revoked_at' => now(),
                'revoked_by' => $actor->id, 'revocation_reason' => trim($reason), 'updated_by' => $actor->id])->save();
            $this->events->record('certificate_revoked', $actor, $certificate, metadata: ['reason' => trim($reason)]);

            return $certificate->fresh();
        });
    }

    public function correct(WarrantyCertificate $certificate, User $actor): WarrantyCertificate
    {
        $this->owner($actor);

        return DB::transaction(function () use ($certificate, $actor): WarrantyCertificate {
            $item = $this->lockedItem($certificate);
            $certificate = WarrantyCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            if (! in_array($certificate->status, [WarrantyCertificate::ISSUED, WarrantyCertificate::REVOKED], true)) {
                $this->invalid('certificate', 'certificate_not_issued');
            }
            $existing = WarrantyCertificate::where('previous_certificate_id', $certificate->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $latest = WarrantyCertificate::where('order_delivery_item_id', $item->id)->where('unit_index', $certificate->unit_index)
                ->lockForUpdate()->get()->max('revision');
            if ((int) $latest !== (int) $certificate->revision) {
                $this->invalid('certificate', 'latest_revision_required');
            }
            $this->source($item, $certificate->unit_index);
            $draft = new WarrantyCertificate([...$certificate->only(['order_id', 'order_item_id', 'order_delivery_item_id', 'product_id',
                'supplier_id', 'policy_id', 'unit_index', 'serial_number', 'not_serialized_reason', 'sale_date', 'delivery_date',
                'manufacturer_date', 'manufacturer_date_proof', 'delivery_date_confirmed', 'delivery_date_reason',
                'manual_term_value', 'manual_term_unit', 'sale_date_source', 'sale_date_source_event_id', 'sale_date_override_reason',
                'supplier_native_sku', 'supplier_native_sku_source', 'supplier_native_sku_reference',
                'supplier_native_sku_reason', 'supplier_native_sku_identity_sha256']),
                'number' => 'IAPI-W-'.Str::ulid(), 'revision' => (int) $latest + 1, 'previous_certificate_id' => $certificate->id,
                'status' => WarrantyCertificate::DRAFT, 'actual_supplier_confirmed' => false,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->refreshSource($draft, $item, $actor);
            $draft->save();
            // Completed supplier originals must be reconfirmed for this correction, not silently copied.
            $this->events->record('certificate_correction_prepared', $actor, $draft, metadata: ['previous_id' => $certificate->id]);

            return $draft->fresh();
        });
    }

    protected function applyInput(WarrantyCertificate $certificate, array $input, bool $requireNativeSkuProof = false): void
    {
        $valid = Validator::make($input, ['policy_id' => ['nullable', 'integer', 'exists:warranty_policies,id'],
            'serial_number' => ['nullable', 'string', 'max:191'], 'not_serialized_reason' => ['nullable', 'string', 'max:1000'],
            'sale_date' => ['nullable', 'date_format:Y-m-d'], 'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'sale_date_override_reason' => ['nullable', 'string', 'max:2000'],
            'supplier_native_sku' => ['nullable', 'string', 'max:191', 'regex:/\A[^\x00-\x1f\x7f]+\z/u'],
            'supplier_native_sku_reference' => ['nullable', 'string', 'max:2000'],
            'supplier_native_sku_reason' => ['nullable', 'string', 'max:2000'],
            'manufacturer_date' => ['nullable', 'date_format:Y-m-d'], 'actual_supplier_confirmed' => ['sometimes', 'boolean'],
            'delivery_date_confirmed' => ['sometimes', 'boolean'], 'delivery_date_reason' => ['nullable', 'string', 'max:2000'],
            'manufacturer_date_proof' => ['nullable', 'string', 'max:3000'],
            'manual_term_value' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'manual_term_unit' => ['nullable', 'in:days,months,years']], [], [
                'manual_term_value' => __('warranty.manual_term_value'),
                'manual_term_unit' => __('warranty.manual_term_unit'),
            ])->validate();
        foreach (['manual_term_value', 'manual_term_unit'] as $field) {
            if (array_key_exists($field, $valid) && $valid[$field] === '') {
                $valid[$field] = null;
            }
        }
        $manualValue = array_key_exists('manual_term_value', $valid) ? $valid['manual_term_value'] : $certificate->manual_term_value;
        $manualUnit = array_key_exists('manual_term_unit', $valid) ? $valid['manual_term_unit'] : $certificate->manual_term_unit;
        if (filled($manualValue) !== filled($manualUnit)) {
            $this->invalid('manual_term_value', 'manual_period_pair_required');
        }
        $maximum = match ($manualUnit) {
            'years' => 100, 'months' => 1200, default => 36500
        };
        Validator::make(['manual_term_value' => $manualValue],
            ['manual_term_value' => ['nullable', 'integer', 'min:1', 'max:'.$maximum]], [],
            ['manual_term_value' => __('warranty.manual_term_value')])->validate();
        if (isset($valid['serial_number'])) {
            $valid['serial_number'] = trim($valid['serial_number']);
        }
        $this->applySaleDate($certificate, $valid);
        $this->applyNativeSku($certificate, $valid, $requireNativeSkuProof);
        unset($valid['sale_date'], $valid['sale_date_override_reason'], $valid['supplier_native_sku'],
            $valid['supplier_native_sku_reference'], $valid['supplier_native_sku_reason']);
        $certificate->fill(array_map(fn ($value) => $value === '' ? null : $value, $valid));
        if ($certificate->policy_id && (int) WarrantyPolicy::findOrFail($certificate->policy_id)->supplier_id !== (int) $certificate->supplier_id) {
            $this->invalid('policy_id', 'policy_supplier_mismatch');
        }
    }

    protected function refreshSource(WarrantyCertificate $certificate, OrderDeliveryItem $item, ?User $actor = null): void
    {
        if ($this->sourceChanged($certificate, $item)) {
            // The old filled card stays private, but cannot satisfy a different supplier's original-card requirement.
            if ($certificate->exists && $certificate->status === WarrantyCertificate::DRAFT) {
                foreach ($certificate->documents()->where('role', 'completed_original')->lockForUpdate()->get() as $document) {
                    DB::table('warranty_documents')->where('id', $document->id)->where('certificate_id', $certificate->id)
                        ->where('role', 'completed_original')->update(['role' => 'source']);
                    $this->events->record('certificate_original_quarantined', $actor, $certificate,
                        metadata: ['document_id' => $document->id, 'reason' => 'completed_original → source']);
                }
                $certificate->unsetRelation('documents');
            }
            $certificate->forceFill(['actual_supplier_confirmed' => false, 'policy_id' => null,
                'serial_number' => null, 'not_serialized_reason' => null, 'serial_fingerprint' => null,
                'supplier_native_sku' => null, 'supplier_native_sku_source' => null, 'supplier_native_sku_reference' => null,
                'supplier_native_sku_reason' => null, 'supplier_native_sku_identity_sha256' => null]);
        }
        $certificate->forceFill(['order_id' => $item->delivery->order_id, 'order_item_id' => $item->order_item_id,
            'product_id' => $item->product_id, 'supplier_id' => $item->supplier_id]);
        if ($item->delivery->delivered_at && ! $certificate->delivery_date) {
            $certificate->delivery_date = $item->delivery->delivered_at->toDateString();
        }
        $this->refreshSaleDate($certificate, $item);
        $this->refreshNativeSku($certificate, $item);
        $this->refreshCustomer($certificate, $item);
    }

    protected function sourceChanged(WarrantyCertificate $certificate, OrderDeliveryItem $item): bool
    {
        if (! $certificate->exists && ! $certificate->supplier_id && ! $certificate->product_id) {
            return false;
        }
        if ((int) $certificate->supplier_id !== (int) $item->supplier_id
            || (int) $certificate->product_id !== (int) $item->product_id) {
            return true;
        }
        $hash = $certificate->supplier_native_sku_identity_sha256;
        $current = filled($hash) ? app(WarrantySupplierIdentity::class)->identityHash($item) : null;

        return filled($hash) && (! $current || ! hash_equals($hash, $current));
    }

    protected function refreshNativeSku(WarrantyCertificate $certificate, OrderDeliveryItem $item): void
    {
        $identity = app(WarrantySupplierIdentity::class)->resolve($item, $certificate);
        $certificate->forceFill(['supplier_native_sku' => $identity['native_sku'],
            'supplier_native_sku_source' => $identity['source'], 'supplier_native_sku_reference' => $identity['reference'],
            'supplier_native_sku_reason' => $identity['source'] === 'staff_confirmed' ? $certificate->supplier_native_sku_reason : null,
            'supplier_native_sku_identity_sha256' => $identity['confirmed'] ? $identity['identity_hash'] : null]);
    }

    protected function applyNativeSku(WarrantyCertificate $certificate, array $valid, bool $requireNativeSkuProof = false): void
    {
        if (! array_key_exists('supplier_native_sku', $valid)) {
            return;
        }
        $native = trim((string) ($valid['supplier_native_sku'] ?? ''));
        $unchanged = $native === $certificate->supplier_native_sku;
        // Optional notes belong to this code: a replacement must not inherit the former code's evidence.
        $reference = trim((string) (array_key_exists('supplier_native_sku_reference', $valid)
            ? $valid['supplier_native_sku_reference'] : ($unchanged ? $certificate->supplier_native_sku_reference : null)));
        $reason = trim((string) (array_key_exists('supplier_native_sku_reason', $valid)
            ? $valid['supplier_native_sku_reason'] : ($unchanged ? $certificate->supplier_native_sku_reason : null)));
        $item = $certificate->deliveryItem;
        if ($native === '') {
            $certificate->forceFill(['supplier_native_sku' => null, 'supplier_native_sku_source' => null,
                'supplier_native_sku_reference' => null, 'supplier_native_sku_reason' => null, 'supplier_native_sku_identity_sha256' => null]);
            $this->refreshNativeSku($certificate, $item);

            return;
        }
        if ($unchanged && ($reference === $certificate->supplier_native_sku_reference || $reference === '')
            && $certificate->supplier_native_sku_source === 'verified_registry' && $reason === '') {
            return; // Saving an auto-filled form must not invent a staff confirmation.
        }
        if ($requireNativeSkuProof && ! $unchanged) {
            // This internal flag is used by the technical API, after the current draft was locked/refreshed.
            Validator::make(['supplier_native_sku_reference' => $reference, 'supplier_native_sku_reason' => $reason], [
                'supplier_native_sku_reference' => ['required', 'string', 'min:5', 'max:2000'],
                'supplier_native_sku_reason' => ['required', 'string', 'min:5', 'max:2000'],
            ])->validate();
        }
        $hash = app(WarrantySupplierIdentity::class)->identityHash($item);
        if (! $hash || (int) $certificate->supplier_id !== (int) $item->supplier_id
            || (int) $certificate->product_id !== (int) $item->product_id
            || (int) $certificate->order_delivery_item_id !== (int) $item->id
            || (int) $certificate->order_id !== (int) $item->delivery?->order_id) {
            $this->invalid('supplier_native_sku', 'supplier_confirmation_required');
        }
        $certificate->forceFill(['supplier_native_sku' => $native, 'supplier_native_sku_source' => 'staff_confirmed',
            'supplier_native_sku_reference' => $reference !== '' ? $reference : null,
            'supplier_native_sku_reason' => $reason !== '' ? $reason : null,
            'supplier_native_sku_identity_sha256' => $hash]);
    }

    protected function nativeSkuState(WarrantyCertificate $certificate): array
    {
        return $certificate->only(['supplier_native_sku', 'supplier_native_sku_source', 'supplier_native_sku_reference',
            'supplier_native_sku_reason', 'supplier_native_sku_identity_sha256']);
    }

    protected function nativeSkuEvent(WarrantyCertificate $certificate, User $actor, array $previous): void
    {
        $state = $this->nativeSkuState($certificate);
        if ($state !== $previous && ($certificate->supplier_native_sku || $previous !== [])) {
            $this->events->record('certificate_supplier_code_confirmed', $actor, $certificate, metadata: [
                'reason' => $certificate->supplier_native_sku_reason,
                'reference' => json_encode(['code' => $certificate->supplier_native_sku, 'source' => $certificate->supplier_native_sku_source,
                    'proof' => $certificate->supplier_native_sku_reference], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    protected function latestCompletionEvent(int $orderId): ?OrderEvent
    {
        if (! Order::whereKey($orderId)->where('status', Order::COMPLETED)->exists()) {
            return null;
        }

        return OrderEvent::where('order_id', $orderId)->where('type', 'status')->where('to', Order::COMPLETED)
            ->orderByDesc('id')->first(['id', 'created_at']);
    }

    protected function completionDate(?OrderEvent $event): ?string
    {
        // The CRM stores/displays its local timestamp; do not shift its calendar day through a second timezone conversion.
        return $event?->created_at?->toDateString();
    }

    protected function refreshSaleDate(WarrantyCertificate $certificate, OrderDeliveryItem $item): void
    {
        // Pre-migration drafts with a date have no provenance. Preserve them until staff explicitly edit the date.
        if (! filled($certificate->sale_date_source) && $certificate->sale_date) {
            return;
        }
        $event = $this->latestCompletionEvent((int) $item->delivery->order_id);
        $certificate->sale_date_source_event_id = $event?->id;
        if ($certificate->sale_date_source === 'manual_override') {
            return;
        }
        $certificate->forceFill(['sale_date' => $this->completionDate($event),
            'sale_date_source' => $event ? 'operator_completion' : null, 'sale_date_override_reason' => null]);
    }

    protected function applySaleDate(WarrantyCertificate $certificate, array $valid): void
    {
        if (! array_key_exists('sale_date', $valid)) {
            return;
        }
        $selected = filled($valid['sale_date']) ? $valid['sale_date'] : null;
        $reason = trim((string) ($valid['sale_date_override_reason'] ?? $certificate->sale_date_override_reason ?? ''));
        $currentDate = $certificate->sale_date?->toDateString();
        if ($selected === $currentDate && ! filled($certificate->sale_date_source) && $reason === '') {
            return; // A normal form save does not relabel an unchanged legacy date.
        }
        $event = $this->latestCompletionEvent((int) $certificate->order_id);
        $automatic = $this->completionDate($event);
        if ($selected === null || $selected === $automatic) {
            $certificate->forceFill(['sale_date' => $automatic, 'sale_date_source_event_id' => $event?->id,
                'sale_date_source' => $automatic ? 'operator_completion' : null, 'sale_date_override_reason' => null]);

            return;
        }
        Validator::make(['sale_date_override_reason' => $reason],
            ['sale_date_override_reason' => ['required', 'string', 'min:5', 'max:2000']], [],
            ['sale_date_override_reason' => __('warranty.sale_date_override_reason')])->validate();
        $certificate->forceFill(['sale_date' => $selected, 'sale_date_source' => 'manual_override',
            'sale_date_source_event_id' => $event?->id, 'sale_date_override_reason' => $reason]);
    }

    protected function saleDateState(WarrantyCertificate $certificate): array
    {
        return [$certificate->sale_date?->toDateString(), $certificate->sale_date_source,
            $certificate->sale_date_source_event_id, $certificate->sale_date_override_reason];
    }

    protected function saleDateEvent(WarrantyCertificate $certificate, User $actor, array $previous): void
    {
        $state = $this->saleDateState($certificate);
        if ($state !== $previous && ($certificate->sale_date || $previous !== [])) {
            $this->events->record('certificate_sale_date_updated', $actor, $certificate, metadata: [
                'reason' => $certificate->sale_date_override_reason,
                'reference' => json_encode(['date' => $state[0], 'source' => $state[1], 'event_id' => $state[2]], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    protected function refreshCustomer(WarrantyCertificate $certificate, OrderDeliveryItem $item): void
    {
        $certificate->customer_name = $item->delivery->order->name;
        $certificate->customer_phone = Phone::dial($item->delivery->order->phone);
    }

    protected function validateDates(WarrantyCertificate $certificate, OrderDeliveryItem $item, string $startBasis = 'delivery_date'): void
    {
        $deliveryRules = [$startBasis === 'delivery_date' || $item->delivery->delivered_at ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        if ($certificate->sale_date_source !== 'operator_completion') {
            $deliveryRules[] = 'after_or_equal:sale_date';
        }
        Validator::make(['sale_date' => $certificate->sale_date?->toDateString(), 'delivery_date' => $certificate->delivery_date?->toDateString(),
            'manufacturer_date' => $certificate->manufacturer_date?->toDateString()],
            ['sale_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'delivery_date' => $deliveryRules,
                'manufacturer_date' => ['nullable', 'date_format:Y-m-d', $certificate->delivery_date ? 'before_or_equal:delivery_date' : 'before_or_equal:today']])->validate();
        if ($item->delivery->delivered_at) {
            if ($item->delivery->delivered_at->toDateString() !== $certificate->delivery_date->toDateString()) {
                $this->invalid('delivery_date', 'delivery_date_mismatch');
            }
        } elseif ($certificate->delivery_date && (! $certificate->delivery_date_confirmed || ! filled($certificate->delivery_date_reason))) {
            $this->invalid('delivery_date_confirmed', 'actual_delivery_required');
        }
    }

    protected function validateUnit(WarrantyCertificate $certificate, OrderDeliveryItem $item): void
    {
        $hasSerial = filled($certificate->serial_number);
        if ($hasSerial === filled($certificate->not_serialized_reason)) {
            $this->invalid('serial_number', 'unit_identity_required');
        }
        if (! $hasSerial) {
            $certificate->serial_fingerprint = null;

            return;
        }
        // Serialize the duplicate check within the supplier namespace, including manually entered product lines.
        Supplier::whereKey($item->supplier_id)->lockForUpdate()->firstOrFail();
        $serial = preg_replace('/\s+/u', '', mb_strtoupper($certificate->serial_number));
        $fingerprint = hash('sha256', json_encode([(int) $item->supplier_id, $item->product_id, $serial], JSON_THROW_ON_ERROR));
        $duplicate = WarrantyCertificate::where('serial_fingerprint', $fingerprint)->where('status', WarrantyCertificate::ISSUED)
            ->where('id', '!=', $certificate->id)
            ->when($certificate->previous_certificate_id, fn ($query) => $query->where('id', '!=', $certificate->previous_certificate_id))->exists();
        if ($duplicate) {
            $this->invalid('serial_number', 'serial_already_issued');
        }
        $certificate->serial_fingerprint = $fingerprint;
    }

    protected function lockedItem(WarrantyCertificate $certificate): OrderDeliveryItem
    {
        if (! $certificate->order_delivery_item_id) {
            $this->invalid('certificate', 'delivery_item_required');
        }

        return OrderDeliveryItem::whereKey($certificate->order_delivery_item_id)->lockForUpdate()->firstOrFail();
    }

    protected function source(OrderDeliveryItem $item, int $unitIndex): void
    {
        $item->loadMissing('delivery.order');
        if (! $item->is_active || $unitIndex < 1 || $unitIndex > (int) $item->qty || ! $item->delivery?->order
            || $item->delivery->order->trashed() || $item->delivery->order->status === Order::CANCELLED
            || $item->delivery->status === OrderDelivery::CANCELLED) {
            $this->invalid('unit_index', 'source_unavailable');
        }
    }

    protected function draft(WarrantyCertificate $certificate): void
    {
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            $this->invalid('certificate', 'certificate_not_draft');
        }
    }

    protected function staff(User $actor): void
    {
        if (! $actor->exists || ! $actor->isStaff()) {
            $this->invalid('authorization', 'staff_required');
        }
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
