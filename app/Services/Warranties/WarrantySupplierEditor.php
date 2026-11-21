<?php

namespace App\Services\Warranties;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Services\Delivery\OrderDeliveryManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reuses the real delivery source editor without running its order/payment/catalog synchronization. */
class WarrantySupplierEditor extends OrderDeliveryManager
{
    public function __construct(private WarrantyCertificateManager $certificates, private WarrantyEventRecorder $events) {}

    public function change(WarrantyCertificate $certificate, int $newSupplierId, User $actor, ?string $expectedFingerprint = null): WarrantyCertificate
    {
        $this->staff($actor);
        Validator::make(['supplier_id' => $newSupplierId], ['supplier_id' => ['required', 'integer', 'min:1', 'exists:suppliers,id']])->validate();

        return DB::transaction(function () use ($certificate, $newSupplierId, $actor, $expectedFingerprint): WarrantyCertificate {
            [$order, $delivery, $item, $current] = $this->context($certificate, true);
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->staff($actor);
            $this->draft($current);
            $this->available($order, $delivery, $item, $current);
            if ($expectedFingerprint !== null) { $this->assertSource($current, $expectedFingerprint); }
            if ($delivery->status === OrderDelivery::ACTIVE) {
                $this->editable($delivery);
            } elseif ($delivery->status === OrderDelivery::DELIVERED) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->fail('supplier_source_unavailable');
            }
            $history = WarrantyCertificate::where('order_delivery_item_id', $item->id)
                ->whereIn('status', [WarrantyCertificate::ISSUED, WarrantyCertificate::REVOKED])->lockForUpdate()->get();
            if ($history->isNotEmpty()) {
                abort_unless($actor->isAdmin(), 403);
                $previous = $history->firstWhere('id', (int) $current->previous_certificate_id);
                if (! $previous || (int) $previous->unit_index !== (int) $current->unit_index
                    || (int) $previous->revision + 1 !== (int) $current->revision) {
                    $this->fail('supplier_correction_required');
                }
            }
            Supplier::whereKey($newSupplierId)->lockForUpdate()->firstOrFail();
            if ((int) $item->supplier_id === $newSupplierId) {
                return $this->refreshChanged($current, $item, $actor);
            }
            // Only the existing supplier override runs: no syncItems, prices, order edits or payment indicators.
            $this->updateItems($delivery, [$item->id => ['supplier_id' => $newSupplierId]], $order, $actor);
            $drafts = WarrantyCertificate::where('order_delivery_item_id', $item->id)
                ->where('status', WarrantyCertificate::DRAFT)->orderBy('id')->lockForUpdate()->get();
            foreach ($drafts as $draft) { $this->reset($draft, $actor); }
            return $current->fresh();
        }, 3);
    }

    public function saveDraft(WarrantyCertificate $certificate, array $input, User $actor, string $expectedHash): WarrantyCertificate
    {
        $this->staff($actor);
        return DB::transaction(function () use ($certificate, $input, $actor, $expectedHash): WarrantyCertificate {
            [, , , $current] = $this->context($certificate, true);
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->staff($actor);
            $this->draft($current);
            $this->assertSource($current, $expectedHash);
            return $this->certificates->saveDraft($current, $input, $actor);
        }, 3);
    }

    /** Explicitly reopening a draft refreshes an externally changed source; render alone never saves. */
    public function refreshDraft(WarrantyCertificate $certificate, User $actor): WarrantyCertificate
    {
        $this->staff($actor);
        return DB::transaction(function () use ($certificate, $actor): WarrantyCertificate {
            [$order, $delivery, $item, $current] = $this->context($certificate, true);
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->staff($actor);
            $this->draft($current);
            if (! $item->is_active || $order->trashed() || $order->status === Order::CANCELLED
                || $delivery->status === OrderDelivery::CANCELLED) { $this->fail('supplier_source_unavailable'); }
            return $this->refreshChanged($current, $item, $actor);
        }, 3);
    }

    public function sourceFingerprint(WarrantyCertificate $certificate): string
    {
        [, , $item] = $this->context($certificate, false);
        return app(WarrantyApproval::class)->identityFingerprint($item);
    }

    /** When called inside the caller's save transaction, hold the actual source row until save/issue finishes. */
    public function assertSource(WarrantyCertificate $certificate, string $expectedHash): void
    {
        [, , $item] = $this->context($certificate, DB::transactionLevel() > 0);
        $actual = app(WarrantyApproval::class)->identityFingerprint($item);
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) || ! hash_equals($expectedHash, $actual)) {
            $this->fail('supplier_source_changed');
        }
    }

    private function context(WarrantyCertificate $certificate, bool $lock): array
    {
        $reference = WarrantyCertificate::findOrFail($certificate->id);
        $source = OrderDeliveryItem::findOrFail($reference->order_delivery_item_id);
        if ($lock) {
            [$order, $delivery] = $this->lockPair((int) $source->order_delivery_id);
        } else {
            $delivery = OrderDelivery::findOrFail($source->order_delivery_id);
            $order = Order::withTrashed()->findOrFail($delivery->order_id);
        }
        $item = OrderDeliveryItem::whereKey($source->id)->where('order_delivery_id', $delivery->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $current = WarrantyCertificate::whereKey($reference->id)->where('order_delivery_item_id', $item->id)
            ->where('order_id', $order->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $delivery->setRelation('order', $order);
        $item->setRelation('delivery', $delivery);
        return [$order, $delivery, $item, $current];
    }

    private function draft(WarrantyCertificate $certificate): void
    {
        if ($certificate->status !== WarrantyCertificate::DRAFT) { $this->fail('certificate_not_draft'); }
        $latest = WarrantyCertificate::where('order_delivery_item_id', $certificate->order_delivery_item_id)
            ->where('unit_index', $certificate->unit_index)->orderByDesc('revision')->orderByDesc('id')->first(['id']);
        if (! $latest || (int) $latest->id !== (int) $certificate->id) { $this->fail('latest_revision_required'); }
    }

    private function available(Order $order, OrderDelivery $delivery, OrderDeliveryItem $item, WarrantyCertificate $certificate): void
    {
        if ($order->trashed() || $order->status !== Order::COMPLETED || ! $delivery->is_eligible || ! $item->is_active
            || $certificate->unit_index < 1 || $certificate->unit_index > (int) $item->qty) {
            $this->fail('supplier_source_unavailable');
        }
    }

    private function refreshChanged(WarrantyCertificate $certificate, OrderDeliveryItem $item, User $actor): WarrantyCertificate
    {
        $identity = app(WarrantySupplierIdentity::class)->identityHash($item);
        $drafts = WarrantyCertificate::where('order_delivery_item_id', $item->id)
            ->where('status', WarrantyCertificate::DRAFT)->orderBy('id')->lockForUpdate()->get();
        foreach ($drafts as $draft) {
            $changed = (int) $draft->supplier_id !== (int) $item->supplier_id
                || (int) $draft->product_id !== (int) $item->product_id
                || (filled($draft->supplier_native_sku_identity_sha256)
                    && (! $identity || ! hash_equals($draft->supplier_native_sku_identity_sha256, $identity)));
            if ($changed) { $this->reset($draft, $actor); }
        }
        return $certificate->fresh();
    }

    private function reset(WarrantyCertificate $certificate, User $actor): WarrantyCertificate
    {
        $beforeSupplier = $certificate->supplier_id;
        $certificate = $this->certificates->saveDraft($certificate, ['policy_id' => null, 'actual_supplier_confirmed' => false,
            'supplier_native_sku' => null, 'serial_number' => null, 'not_serialized_reason' => null], $actor);
        $this->events->record('certificate_supplier_source_reset', $actor, $certificate,
            metadata: ['previous_id' => $beforeSupplier, 'reference' => (string) $certificate->supplier_id]);
        return $certificate;
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['supplier_id' => __('warranty.errors.'.$key)]);
    }
}
