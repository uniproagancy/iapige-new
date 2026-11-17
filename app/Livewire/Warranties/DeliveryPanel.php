<?php

namespace App\Livewire\Warranties;

use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantySmsMessage;
use App\Services\Warranties\WarrantyCourierAccess;
use App\Services\Warranties\WarrantyCourierSerial;
use App\Services\Warranties\WarrantySms;
use App\Services\Warranties\WarrantySmsGateway;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Couriers may complete only the serial of an explicitly released staff draft. */
class DeliveryPanel extends Component
{
    #[Locked]
    public int $deliveryId;

    #[Locked]
    public array $itemIds = [];

    #[Locked]
    public array $smsKeys = [];

    #[Locked]
    public array $smsResendKeys = [];

    #[Locked]
    public ?string $sendStatus = null;

    #[Locked]
    public array $releaseHashes = [];

    public array $serialNumbers = [];

    public string $serialNotice = '';

    public function mount(int $deliveryId, array $itemIds = []): void
    {
        $this->deliveryId = $deliveryId;
        abort_unless(collect($itemIds)->every(fn ($id) => is_int($id) && $id > 0), 404);
        $this->itemIds = array_values(array_unique($itemIds));
        $this->certificates($this->delivery());
    }

    public function send(int $certificateId): void
    {
        $actor = $this->actor();
        $access = app(WarrantyCourierAccess::class);
        $delivery = $access->delivery($actor, $this->deliveryId);
        $certificate = $this->certificates($delivery)->whereKey($certificateId)->firstOrFail();
        $key = $this->key($certificateId);
        $message = app(WarrantySms::class)->sendForCourier($certificate, $actor, $delivery->id, $key);
        $this->sendStatus = $message->status;
        if ($message->status === 'rejected') {
            // Only an explicit subsequent click may retry a confirmed rejection.
            $this->smsKeys[$certificateId] = (string) Str::uuid();
        }
    }

    public function resend(int $certificateId, int $previousAttemptId): void
    {
        $actor = $this->actor();
        $delivery = app(WarrantyCourierAccess::class)->delivery($actor, $this->deliveryId);
        $certificate = $this->certificates($delivery)->whereKey($certificateId)->firstOrFail();
        $key = $this->resendKey($certificate->id, $previousAttemptId);
        $message = app(WarrantySms::class)->resendForCourier($certificate, $actor, $delivery->id, $key, $previousAttemptId);
        $this->sendStatus = $message->status;
    }

    public function completeSerial(int $certificateId): void
    {
        $actor = $this->actor();
        $delivery = $this->delivery();
        // Replayed requests may refer to the just-issued result. The service still requires the
        // exact staff release token and matching serial; this does not permit issued edits.
        $certificate = WarrantyCertificate::query()->where('order_id', $delivery->order_id)
            ->whereHas('deliveryItem', fn (Builder $query) => $query->where('order_delivery_id', $delivery->id)
                ->where('is_active', true)->when($this->itemIds !== [], fn (Builder $scope) => $scope->whereIn('id', $this->itemIds)))
            ->findOrFail($certificateId);
        $input = $this->validate(['serialNumbers.'.$certificateId => ['required', 'string', 'max:191', 'not_regex:/[\x00-\x1F\x7F]/u']]);
        $hash = $this->releaseHashes[$certificateId] ?? '';
        abort_unless(is_string($hash) && $hash !== '', 422);
        app(WarrantyCourierSerial::class)->finalize($certificate, $actor, $delivery->id,
            $input['serialNumbers'][$certificateId], $hash);
        unset($this->serialNumbers[$certificateId], $this->releaseHashes[$certificateId]);
        $this->sendStatus = null;
        $this->serialNotice = __('warranty.courier.serial_saved');
    }

    public function render()
    {
        $delivery = $this->delivery();
        $certificates = $this->certificates($delivery)
            ->orderBy('order_delivery_item_id')->orderBy('unit_index')->get();
        $releasedDrafts = $this->releasedDrafts($delivery)->with('deliveryItem')
            ->orderBy('order_delivery_item_id')->orderBy('unit_index')->get();
        $releaseStates = [];
        foreach ($releasedDrafts as $certificate) {
            $state = app(WarrantyCourierSerial::class)->releaseState($certificate);
            $releaseStates[$certificate->id] = $state;
            if ($state['ready'] && is_string($state['release_hash']) && $state['release_hash'] !== '') {
                if (($this->releaseHashes[$certificate->id] ?? null) !== $state['release_hash']) {
                    $this->serialNumbers[$certificate->id] = '';
                }
                $this->releaseHashes[$certificate->id] = $state['release_hash'];
            } else {
                unset($this->releaseHashes[$certificate->id]);
            }
        }
        $attempts = WarrantySmsMessage::query()->whereIn('warranty_certificate_id', $certificates->modelKeys())
            ->orderByDesc('id')->get()
            ->groupBy('warranty_certificate_id')->map(fn ($group) => $group->first());
        $resendStates = [];
        foreach ($certificates as $certificate) {
            $this->key($certificate->id);
            $attempt = $attempts->get($certificate->id);
            if ($attempt) { $this->resendKey($certificate->id, $attempt->id); }
            $resendStates[$certificate->id] = app(WarrantySms::class)->resendState($attempt);
        }

        return view('livewire.warranties.delivery-panel', [
            'certificates' => $certificates, 'attempts' => $attempts, 'resendStates' => $resendStates,
            'releasedDrafts' => $releasedDrafts, 'releaseStates' => $releaseStates,
            'sendingEnabled' => (bool) config('warranty.sms_enabled') && (bool) config('warranty.public_enabled')
                && app(WarrantySmsGateway::class)->ready(),
        ]);
    }

    private function key(int $certificateId): string
    {
        if (! isset($this->smsKeys[$certificateId]) || ! Str::isUuid($this->smsKeys[$certificateId])) {
            $this->smsKeys[$certificateId] = (string) Str::uuid();
        }

        return $this->smsKeys[$certificateId];
    }

    private function resendKey(int $certificateId, int $previousAttemptId): string
    {
        abort_unless($previousAttemptId > 0, 422);
        $slot = $certificateId.'_'.$previousAttemptId;
        if (! isset($this->smsResendKeys[$slot]) || ! Str::isUuid($this->smsResendKeys[$slot])) {
            $this->smsResendKeys[$slot] = (string) Str::uuid();
        }

        return $this->smsResendKeys[$slot];
    }

    private function delivery(): OrderDelivery
    {
        return app(WarrantyCourierAccess::class)->delivery($this->actor(), $this->deliveryId);
    }

    private function certificates(OrderDelivery $delivery): Builder
    {
        $query = app(WarrantyCourierAccess::class)->certificates($delivery);
        if ($this->itemIds !== []) {
            abort_unless(OrderDeliveryItem::query()->where('order_delivery_id', $delivery->id)
                ->where('is_active', true)->whereIn('id', $this->itemIds)->count() === count($this->itemIds), 404);
            $query->whereIn('order_delivery_item_id', $this->itemIds);
        }

        return $query;
    }

    private function releasedDrafts(OrderDelivery $delivery): Builder
    {
        $query = app(WarrantyCourierAccess::class)->releasedDrafts($delivery);
        if ($this->itemIds !== []) {
            abort_unless(OrderDeliveryItem::query()->where('order_delivery_id', $delivery->id)
                ->where('is_active', true)->whereIn('id', $this->itemIds)->count() === count($this->itemIds), 404);
            $query->whereIn('order_delivery_item_id', $this->itemIds);
        }

        return $query;
    }

    private function actor(): User
    {
        abort_unless(config('warranty.enabled'), 404);
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->isCourier(), 403);

        return $actor;
    }
}
