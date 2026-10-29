<?php

namespace App\Services\Facebook;

use App\Jobs\SendPixelEventJob;
use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Pixel
{
    protected const TIMEOUT = 5;

    protected array $sent = [];

    protected string $endpoint;

    public function __construct()
    {
        $this->endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            config('services.facebook.api_version'),
            (string) config('services.facebook.pixel_id'),
        );
    }

    public function pixelId(): ?string
    {
        return config('services.facebook.pixel_id') ?: null;
    }

    public function enabled(): bool
    {
        return $this->configured() && $this->consented();
    }

    protected function configured(): bool
    {
        return (bool) ($this->pixelId() && config('services.facebook.access_token'));
    }

    public function staff(): bool
    {
        return Gate::allows('admin');
    }

    public function consented(): bool
    {
        if ($this->staff()) {
            return false;
        }

        return request()->cookie('cookie_consent') === 'all';
    }

    public function testMode(): bool
    {
        return (bool) config('services.facebook.test_event_code');
    }

    public function viewContent(array $card): array
    {
        return $this->pair('ViewContent', 'vc', [
            'content_type' => 'product',
            'content_ids' => [(string) $card['id']],
            'content_name' => $this->nameOf($card),
            'value' => round((float) ($card['price'] ?? 0)),
            'currency' => 'GEL',
            'contents' => $this->contents([$card + ['qty' => 1]]),
        ]);
    }

    public function addToCart(array $line): array
    {
        $qty = max(1, (int) ($line['qty'] ?? 1));

        return $this->pair('AddToCart', 'atc', [
            'content_type' => 'product',
            'content_ids' => [(string) $line['id']],
            'content_name' => $this->nameOf($line),
            'value' => round((float) ($line['price'] ?? 0) * $qty),
            'currency' => 'GEL',
            'contents' => $this->contents([$line + ['qty' => $qty]]),
        ]);
    }

    public function initiateCheckout(array $lines, float $value): array
    {
        return $this->pair('InitiateCheckout', 'ic', [
            'content_type' => 'product',
            'content_ids' => array_map(fn ($l) => (string) $l['id'], $lines),
            'contents' => $this->contents($lines),
            'num_items' => array_sum(array_map(fn ($l) => (int) ($l['qty'] ?? 1), $lines)),
            'value' => round($value),
            'currency' => 'GEL',
        ]);
    }

    public function addToWishlist(array $card): array
    {
        return $this->pair('AddToWishlist', 'wl', [
            'content_type' => 'product',
            'content_ids' => [(string) $card['id']],
            'content_name' => $this->nameOf($card),
            'value' => round((float) ($card['price'] ?? 0)),
            'currency' => 'GEL',
        ]);
    }

    public function search(string $term): array
    {
        return $this->pair('Search', 'sr', ['search_string' => $term]);
    }

    public function lead(array $identity = []): array
    {
        return $this->pair('Lead', 'ld', [], $identity);
    }

    public function savedLead(CallbackRequest $lead): array
    {
        $data = [];

        if ($lead->product_id && $lead->unit_price !== null) {
            $data = [
                'content_type' => 'product',
                'content_ids' => [(string) $lead->product_id],
                'content_name' => $lead->product_name,
                'contents' => $this->contents([[
                    'id' => $lead->product_id,
                    'qty' => $lead->quantity,
                    'price' => $lead->unit_price,
                ]]),
                'num_items' => (int) $lead->quantity,
                'value' => round((float) $lead->total, 2),
                'currency' => $lead->currency ?: 'GEL',
            ];
        }

        return $this->storedLeadPair('Lead', $lead, (string) $lead->lead_event_id, $data, $lead->created_at?->getTimestamp());
    }

    public function quickCheckout(CallbackRequest $lead, Order $order): array
    {
        return $this->storedLeadPair(
            'InitiateCheckout',
            $lead,
            (string) $lead->checkout_event_id,
            $lead->checkout_data ?: $this->purchaseData($order->loadMissing('items')),
            $lead->checkout_started_at?->getTimestamp(),
        );
    }

    protected function storedLeadPair(string $event, CallbackRequest $lead, string $eventId, array $data, ?int $occurredAt): array
    {
        if (! $lead->consented || ! $eventId || ! $occurredAt || ! $this->pixelId()) {
            return [];
        }

        $identity = ['name' => $lead->name, 'phone' => $lead->phone, 'id' => $lead->external_id ?: $lead->user_id];
        $visitor = array_filter([
            'fbp' => $lead->fbp,
            'fbc' => $lead->fbc,
            'client_ip_address' => $lead->ip,
            'client_user_agent' => $lead->user_agent,
        ]);
        $payload = [
            'event_name' => $event,
            'event_id' => $eventId,
            'event_time' => $occurredAt,
            'event_source_url' => $lead->source_url,
            'action_source' => 'website',
            'user_data' => array_merge($visitor, $this->hashedIdentity($identity)),
        ];

        if ($data) {
            $payload['custom_data'] = $data;
        }

        if ($this->configured()) {
            try {
                $this->dispatchEvent($event, $payload);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['event' => $event, 'id' => $eventId, 'data' => $data];
    }

    public function completeRegistration(User $user): array
    {
        return $this->pair('CompleteRegistration', 'cr', [], [
            'email' => $user->email,
            'phone' => $user->phone,
            'name' => $user->name,
            'id' => $user->id,
        ]);
    }

    public function pair(string $event, string $prefix, array $customData, array $identity = []): array
    {
        $eventId = self::eventId($prefix);

        if ($payload = $this->capture($event, $customData, $eventId, $identity)) {
            $this->dispatchEvent($event, $payload);
        }

        return ['event' => $event, 'id' => $eventId, 'data' => $customData];
    }

    public function purchase(Order $order, ?string $eventId = null, ?OrderPixelData $snapshot = null): bool
    {
        if (! $this->isSale($order)) {
            return false;
        }

        return $this->send('Purchase', $snapshot?->custom_data ?: $this->purchaseData($order), $eventId ?? $snapshot?->event_id, [
            'email' => $order->email,
            'phone' => $order->phone,
            'name' => $order->name,
            'city' => $order->city,
            'id' => $snapshot?->external_id ?: ($order->user_id ?: $order->number),
        ], $snapshot);
    }

    public function reportPurchase(Order $order, bool $offline = false): bool
    {
        if (! $this->configured()) {
            return false;
        }

        return DB::transaction(function () use ($order, $offline) {
            $order = $order->fresh('items');
            $snapshot = OrderPixelData::where('order_id', $order?->id)->lockForUpdate()->first();

            if (! $order || ! $this->isSale($order) || ! $snapshot || ! $snapshot->consented || $snapshot->alreadySent()) {
                return false;
            }

            $this->freezePurchase($order, $snapshot, $offline);

            if (! $this->purchase($order, $snapshot->event_id, $snapshot)) {
                $payload = $this->payload('Purchase', $snapshot->custom_data, $snapshot->event_id, [], $snapshot);
                DB::afterCommit(function () use ($snapshot, $payload) {
                    try {
                        SendPixelEventJob::dispatch('Purchase', $payload, $snapshot->id);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });

                return false;
            }

            $snapshot->markSent();

            return true;
        });
    }

    /*
     | An order written in the admin or taken over the telephone has no browser
     | behind it to remember anything from, and the operator sitting in front
     | of it is not the customer. The snapshot is what makes the event carry
     | the order's own details, so an order that never had one is given one.
     */
    public function snapshotForStaffOrder(Order $order): OrderPixelData
    {
        return OrderPixelData::firstOrCreate(['order_id' => $order->id], [
            'event_id' => self::eventId('pus'),
            'source_url' => route('order', $order->number),
            'consented' => true,
            'action_source' => 'phone',
            'external_id' => (string) ($order->user_id ?: $order->number),
        ]);
    }

    /*
     | A corrected order is a different sale. While nothing has gone to Meta the
     | frozen copy is dropped, so the next report reads the order as it now
     | stands. Once it has gone, Meta keeps the figures it was given.
     */
    public function refreshAfterEdit(Order $order): bool
    {
        $snapshot = OrderPixelData::where('order_id', $order->id)->first();

        if (! $snapshot || $snapshot->alreadySent() || $snapshot->browser_sent_at) {
            return false;
        }

        $snapshot->forceFill([
            'occurred_at' => null,
            'custom_data' => null,
            'user_data' => null,
        ])->save();

        return true;
    }

    public function browserPurchase(Order $order): array
    {
        if ($this->staff()) {
            return [];
        }

        $this->reportPurchase($order);

        return DB::transaction(function () use ($order) {
            $order = $order->fresh('items');
            $snapshot = OrderPixelData::where('order_id', $order?->id)->lockForUpdate()->first();

            if (! $order || ! $this->isSale($order) || ! $snapshot?->consented || $snapshot->browser_sent_at || ! $this->pixelId()) {
                return [];
            }

            $this->freezePurchase($order, $snapshot, false);
            $snapshot->forceFill(['browser_sent_at' => now()])->save();

            return ['event' => 'Purchase', 'id' => $snapshot->event_id, 'data' => $snapshot->custom_data];
        });
    }

    protected function isSale(Order $order): bool
    {
        return $order->status !== Order::CANCELLED && ($order->is_paid || $order->status === Order::COMPLETED);
    }

    protected function freezePurchase(Order $order, OrderPixelData $snapshot, bool $offline): void
    {
        if ($snapshot->occurred_at) {
            return;
        }

        $identity = [
            'email' => $order->email,
            'phone' => $order->phone,
            'name' => $order->name,
            'city' => $order->city,
            'id' => $snapshot->external_id ?: ($order->user_id ?: $order->number),
        ];
        $snapshot->forceFill([
            'occurred_at' => $order->paid_at ?? $order->updated_at ?? now(),
            'action_source' => $snapshot->action_source ?: 'website',
            'custom_data' => $this->purchaseData($order),
            'user_data' => $this->userData($identity, $snapshot),
        ])->save();
    }

    public function purchaseData(Order $order): array
    {
        $lines = $order->items->map(fn ($item) => [
            'id' => $item->product_id,
            'qty' => $item->qty,
            'price' => $item->price,
        ])->all();

        return [
            'content_type' => 'product',
            'content_ids' => array_map(fn ($l) => (string) $l['id'], $lines),
            'contents' => $this->contents($lines),
            'num_items' => array_sum(array_column($lines, 'qty')),
            'value' => round((float) $order->total, 2),
            'currency' => 'GEL',
            'order_id' => $order->number,
        ];
    }

    public function send(
        string $event,
        array $customData = [],
        ?string $eventId = null,
        array $identity = [],
        ?OrderPixelData $snapshot = null,
    ): bool {
        if (! $this->configured() || (! $this->enabled() && ! $snapshot)) {
            return false;
        }

        if ($snapshot && ! $snapshot->consented) {
            return false;
        }

        $key = $event.':'.md5(json_encode($customData).($eventId ?? ''));

        if (isset($this->sent[$key])) {
            return false;
        }

        $this->sent[$key] = true;

        return $this->post($event, $this->payload($event, $customData, $eventId, $identity, $snapshot));
    }

    public function capture(
        string $event,
        array $customData = [],
        ?string $eventId = null,
        array $identity = [],
    ): ?array {
        if (! $this->enabled()) {
            return null;
        }

        return $this->payload($event, $customData, $eventId, $identity);
    }

    public function deliver(string $event, array $payload): bool
    {
        return $this->configured() && $this->post($event, $payload);
    }

    public function dispatchEvent(string $event, array $payload): void
    {
        if (config('services.facebook.queue_events', true)) {
            SendPixelEventJob::dispatch($event, $payload)->afterCommit();

            return;
        }

        if (! $this->post($event, $payload)) {
            SendPixelEventJob::dispatch($event, $payload)->afterCommit();
        }
    }

    protected function payload(
        string $event,
        array $customData,
        ?string $eventId,
        array $identity,
        ?OrderPixelData $snapshot = null,
    ): array {
        $payload = [
            'event_name' => $event,
            'event_time' => $snapshot?->occurred_at?->getTimestamp() ?? time(),
            'event_source_url' => $snapshot?->source_url ?? request()->url(),
            'action_source' => $snapshot?->action_source ?: 'website',
            'user_data' => $snapshot?->user_data ?: $this->userData($identity, $snapshot),
        ];

        if ($eventId) {
            $payload['event_id'] = $eventId;
        }

        if ($customData) {
            $payload['custom_data'] = $customData;
        }

        return $payload;
    }

    protected function post(string $event, array $payload): bool
    {
        $body = [
            'data' => [$payload],
            'access_token' => config('services.facebook.access_token'),
        ];

        if ($code = config('services.facebook.test_event_code')) {
            $body['test_event_code'] = $code;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->post($this->endpoint, $body);

            if ($response->successful() && (int) $response->json('events_received', 0) > 0) {
                Log::channel('pixel')->info('sent', [
                    'event' => $event,
                    'event_id' => $payload['event_id'] ?? null,
                    'test' => $this->testMode(),
                ]);

                return true;
            }

            Log::channel('pixel')->error('refused', [
                'event' => $event,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {

            Log::channel('pixel')->error('failed', ['event' => $event, 'error' => $e->getMessage()]);
        }

        return false;
    }

    protected function userData(array $identity = [], ?OrderPixelData $snapshot = null): array
    {
        $data = $snapshot
            ? array_filter([
                'client_ip_address' => $snapshot->ip,
                'client_user_agent' => $snapshot->user_agent,
                'fbp' => $snapshot->fbp,
                'fbc' => $snapshot->fbc,
            ])
            : $this->browserData();

        if (! $identity && ($user = auth()->user())) {
            $identity = ['email' => $user->email, 'phone' => $user->phone, 'name' => $user->name, 'id' => $user->id];
        }

        return array_merge($data, $this->hashedIdentity($identity));
    }

    public function browserData(): array
    {
        $data = array_filter([
            'client_ip_address' => request()->ip(),
            'client_user_agent' => request()->userAgent(),
            'fbp' => request()->cookie('_fbp'),
            'fbc' => request()->cookie('_fbc') ?: $this->fbcFromClick(),
        ]);

        return $data;
    }

    protected function fbcFromClick(): ?string
    {
        $fbclid = request()->query('fbclid');

        return $fbclid ? 'fb.1.'.(int) (microtime(true) * 1000).'.'.$fbclid : null;
    }

    public function hashedIdentity(array $identity): array
    {
        $out = [];

        if ($email = trim((string) ($identity['email'] ?? ''))) {
            $out['em'] = hash('sha256', Str::lower($email));
        }

        if ($phone = Phone::digits($identity['phone'] ?? null)) {
            $out['ph'] = hash('sha256', $phone);
        }

        if ($name = trim((string) ($identity['name'] ?? ''))) {
            $parts = preg_split('/\s+/u', $name);
            $out['fn'] = hash('sha256', Str::lower($parts[0]));

            if (count($parts) > 1) {
                $out['ln'] = hash('sha256', Str::lower(end($parts)));
            }
        }

        if ($city = trim((string) ($identity['city'] ?? ''))) {
            $out['ct'] = hash('sha256', Str::lower($city));
        }

        if ($id = $identity['id'] ?? null) {
            $out['external_id'] = hash('sha256', (string) $id);
        }

        $out['country'] = hash('sha256', 'ge');

        return $out;
    }

    protected function contents(array $lines): array
    {
        return array_map(fn ($l) => [
            'id' => (string) $l['id'],
            'quantity' => (int) ($l['qty'] ?? 1),
            'item_price' => round((float) ($l['price'] ?? 0), 2),
        ], $lines);
    }

    protected function nameOf(array $card): string
    {
        return product_title($card['brand'] ?? null, $card['name'] ?? null);
    }

    public static function eventId(string $prefix): string
    {
        return $prefix.'_'.time().'_'.Str::random(8);
    }
}
