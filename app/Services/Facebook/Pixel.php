<?php

namespace App\Services\Facebook;

use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Meta Conversions API — the server half of the pixel.
 *
 * Every event is sent twice: the browser fires it with an eventID, this sends
 * the same event with the same event_id, and Meta keeps one of them. The pair
 * is what makes the numbers survive ad blockers and iOS without double-counting.
 *
 * Nothing here may throw. A tracking call sits in the middle of checkout, and
 * an advertising platform having a bad afternoon must not cost an order — so
 * every failure is logged and swallowed.
 */
class Pixel
{
    /** Short: this runs inside a request the customer is waiting on. */
    protected const TIMEOUT = 5;

    /**
     * One event per payload per request; Livewire re-renders would resend.
     *
     * Not static: a static array would survive the request and mute the event
     * for the whole life of a worker process. The container holds this as a
     * singleton, which is exactly one request long.
     */
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

    /** Configured, and allowed by whoever is reading the page. */
    public function enabled(): bool
    {
        return $this->pixelId()
            && config('services.facebook.access_token')
            && $this->consented();
    }

    /**
     * The cookie banner's answer.
     *
     * The shop asks before it tracks, so "necessary only" has to mean exactly
     * that — otherwise the banner is decoration. The browser mirrors its choice
     * into a cookie so this side can read it too.
     */
    public function consented(): bool
    {
        return request()->cookie('cookie_consent') === 'all';
    }

    public function testMode(): bool
    {
        return (bool) config('services.facebook.test_event_code');
    }

    /* ================================================================== events */

    /*
     | Each of these sends the server half and hands back what the browser half
     | should fire — one payload, built once, so the two halves can never
     | describe different things. A component passes the result straight on, as
     | one argument:
     |
     |     $this->dispatch('pixel', app(Pixel::class)->addToCart($line));
     |
     | Not spread. Livewire's signature is dispatch($event, ...$params), and the
     | "event" key here would arrive as a named argument for that first
     | parameter — "Named parameter $event overwrites previous argument".
     */

    /** @param  array<string, mixed>  $card  a Catalog::card() array */
    public function viewContent(array $card): array
    {
        return $this->pair('ViewContent', 'vc', [
            'content_type' => 'product',
            'content_ids' => [(string) $card['id']],
            'content_name' => $this->nameOf($card),
            'value' => round((float) ($card['price'] ?? 0), 2),
            'currency' => 'GEL',
            'contents' => $this->contents([$card + ['qty' => 1]]),
        ]);
    }

    /** @param  array<string, mixed>  $line  id, name, price, qty */
    public function addToCart(array $line): array
    {
        $qty = max(1, (int) ($line['qty'] ?? 1));

        return $this->pair('AddToCart', 'atc', [
            'content_type' => 'product',
            'content_ids' => [(string) $line['id']],
            'content_name' => $this->nameOf($line),
            'value' => round((float) ($line['price'] ?? 0) * $qty, 2),
            'currency' => 'GEL',
            'contents' => $this->contents([$line + ['qty' => $qty]]),
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function initiateCheckout(array $lines, float $value): array
    {
        return $this->pair('InitiateCheckout', 'ic', [
            'content_type' => 'product',
            'content_ids' => array_map(fn ($l) => (string) $l['id'], $lines),
            'contents' => $this->contents($lines),
            'num_items' => array_sum(array_map(fn ($l) => (int) ($l['qty'] ?? 1), $lines)),
            'value' => round($value, 2),
            'currency' => 'GEL',
        ]);
    }

    /** @param  array<string, mixed>  $card */
    public function addToWishlist(array $card): array
    {
        return $this->pair('AddToWishlist', 'wl', [
            'content_type' => 'product',
            'content_ids' => [(string) $card['id']],
            'content_name' => $this->nameOf($card),
            'value' => round((float) ($card['price'] ?? 0), 2),
            'currency' => 'GEL',
        ]);
    }

    public function search(string $term): array
    {
        return $this->pair('Search', 'sr', ['search_string' => $term]);
    }

    /** @param  array<string, mixed>  $identity  plain values, hashed before sending */
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

    /**
     * Sends the server half, returns the browser half.
     *
     * @param  array<string, mixed>  $customData
     * @param  array<string, mixed>  $identity
     * @return array{event: string, id: string, data: array<string, mixed>}
     */
    public function pair(string $event, string $prefix, array $customData, array $identity = []): array
    {
        $eventId = self::eventId($prefix);

        $this->send($event, $customData, $eventId, $identity);

        return ['event' => $event, 'id' => $eventId, 'data' => $customData];
    }

    /**
     * The one event the shop is actually paid for.
     *
     * A card payment is confirmed by a bank callback or by the scheduler, with
     * no browser anywhere near it — so the cookies and the address are read
     * from the snapshot taken while the customer was still on the page.
     */
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

    /**
     * What the Purchase event says, for either half.
     *
     * The browser one has to describe exactly the same sale as the server one,
     * or the pair Meta joins by event_id disagrees with itself.
     *
     * @return array<string, mixed>
     */
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

    /* ================================================================== sending */

    /**
     * @param  array<string, mixed>  $customData
     * @param  array<string, mixed>  $identity  plain values; hashed before they leave
     */
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

        // a snapshot carries its own consent, taken when the customer gave it
        if ($snapshot && ! $snapshot->consented) {
            return false;
        }

        $key = $event.':'.md5(json_encode($customData).($eventId ?? ''));

        if (isset($this->sent[$key])) {
            return false;
        }

        $this->sent[$key] = true;

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

        return $this->post($event, $payload);
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
            // an advertising platform must never cost us an order
            Log::channel('pixel')->error('failed', ['event' => $event, 'error' => $e->getMessage()]);
        }

        return false;
    }

    /* ================================================================== user data */

    /**
     * Who this was, as far as Meta is allowed to know.
     *
     * Every personal field is SHA-256 of its normalised value: Meta matches on
     * the hash, so the plain address never leaves this server.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
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

    /** @return array<string, string> */
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

    /**
     * A click id in the URL is as good as the cookie Meta would have set.
     *
     * Someone arriving from an advert before the pixel has loaded still has
     * ?fbclid= in the address, and that is what ties the visit to the ad.
     */
    protected function fbcFromClick(): ?string
    {
        $fbclid = request()->query('fbclid');

        return $fbclid ? 'fb.1.'.(int) (microtime(true) * 1000).'.'.$fbclid : null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, string>
     */
    public function hashedIdentity(array $identity): array
    {
        $out = [];

        if ($email = trim((string) ($identity['email'] ?? ''))) {
            $out['em'] = hash('sha256', Str::lower($email));
        }

        // Meta wants digits only, country code included
        $phone = preg_replace('/\D/', '', (string) ($identity['phone'] ?? ''));

        if (strlen((string) $phone) >= 9) {
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

    /* ================================================================== helpers */

    /** @param  array<int, array<string, mixed>>  $lines */
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
        return trim(($card['brand'] ?? '').' '.($card['name'] ?? ''));
    }

    /** A fresh id for a browser/server pair. */
    public static function eventId(string $prefix): string
    {
        return $prefix.'_'.time().'_'.Str::random(8);
    }
}
