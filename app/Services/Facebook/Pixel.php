<?php

namespace App\Services\Facebook;

use App\Jobs\SendPixelEventJob;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\User;
use App\Support\Phone;
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
        return $this->pixelId()
            && config('services.facebook.access_token')
            && $this->consented();
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
        return $this->send('Purchase', $this->purchaseData($order), $eventId ?? $snapshot?->event_id, [
            'email' => $order->email,
            'phone' => $order->phone,
            'name' => $order->name,
            'city' => $order->city,
            'id' => $order->user_id ?: $order->number,
        ], $snapshot);
    }

    public function reportPurchase(Order $order, bool $offline = false): bool
    {
        $snapshot = OrderPixelData::where('order_id', $order->id)->first();

        if ($snapshot?->alreadySent()) {
            return false;
        }

        if (! $snapshot) {

            if (! $offline) {
                return false;
            }

            $snapshot = OrderPixelData::create([
                'order_id' => $order->id,
                'event_id' => self::eventId('pur'),
                'consented' => true,
            ]);
        }

        if (! $this->purchase($order->loadMissing('items'), $snapshot->event_id, $snapshot)) {
            return false;
        }

        $snapshot->markSent();

        return true;
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
            'value' => round((float) $order->total),
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
        if (! $this->enabled() && ! $snapshot) {
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
        return $this->post($event, $payload);
    }

    public function dispatchEvent(string $event, array $payload): void
    {
        if (config('services.facebook.queue_events', true)) {
            SendPixelEventJob::dispatch($event, $payload);

            return;
        }

        $this->post($event, $payload);
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
            'event_time' => time(),
            'event_source_url' => $snapshot?->source_url ?? request()->url(),
            'action_source' => 'website',
            'user_data' => $this->userData($identity, $snapshot),
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

            if ($response->successful()) {
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
            'item_price' => round((float) ($l['price'] ?? 0)),
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
