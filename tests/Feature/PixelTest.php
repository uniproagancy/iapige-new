<?php

namespace Tests\Feature;

use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class PixelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.pixel_id' => '111122223333',
            'services.facebook.access_token' => 'test-token',
            'services.facebook.api_version' => 'v24.0',
            'services.facebook.test_event_code' => null,
        ]);
    }

    /** Stubs are matched in the order they are registered, so each test sets its own. */
    protected function fakeMeta(int $status = 200): void
    {
        Http::fake(fn () => Http::response(['events_received' => 1], $status));
    }

    /** The cookie banner is the switch; "necessary only" has to mean it. */
    public function test_nothing_is_sent_without_consent(): void
    {
        $this->fakeMeta();

        $this->assertFalse(app(Pixel::class)->enabled());

        app(Pixel::class)->send('PageView');

        Http::assertNothingSent();
    }

    public function test_consent_cookie_enables_sending(): void
    {
        $this->withConsent();

        $this->assertTrue(app(Pixel::class)->enabled());

        app(Pixel::class)->send('PageView', [], 'pv_1');

        Http::assertSent(fn ($r) => $r['data'][0]['event_name'] === 'PageView'
            && $r['data'][0]['event_id'] === 'pv_1'
            && $r['data'][0]['action_source'] === 'website');
    }

    /** content_ids must be the numeric id FacebookFeed publishes as g:id. */
    public function test_add_to_cart_payload(): void
    {
        $this->withConsent();

        $browser = app(Pixel::class)->addToCart([
            'id' => 142, 'brand' => 'NORDA', 'name' => 'Pro 14', 'price' => 4780.0, 'qty' => 2,
        ]);

        $this->assertSame('AddToCart', $browser['event']);
        $this->assertSame(['142'], $browser['data']['content_ids']);
        $this->assertSame(9560.0, $browser['data']['value']);
        $this->assertSame([['id' => '142', 'quantity' => 2, 'item_price' => 4780.0]], $browser['data']['contents']);

        // the server half must describe the same sale, under the same id
        Http::assertSent(function ($r) use ($browser) {
            $event = $r['data'][0];

            return $event['event_name'] === 'AddToCart'
                && $event['event_id'] === $browser['id']
                && $event['custom_data']['content_ids'] === ['142']
                && $event['custom_data']['value'] === 9560.0;
        });
    }

    /** Personal data is matched on its hash; the plain value never leaves. */
    public function test_identity_is_hashed(): void
    {
        $this->withConsent();

        app(Pixel::class)->send('Lead', [], 'ld_1', [
            'email' => '  Nino@Example.COM ',
            'phone' => '+995 555 12 34 56',
            'name' => 'ნინო ბერიძე',
            'id' => 42,
        ]);

        Http::assertSent(function ($r) {
            $user = $r['data'][0]['user_data'];

            return $user['em'] === hash('sha256', 'nino@example.com')
                && $user['ph'] === hash('sha256', '995555123456')
                && $user['external_id'] === hash('sha256', '42')
                && ! str_contains(json_encode($user), 'example.com');
        });
    }

    /** A Livewire re-render must not report the same thing twice. */
    public function test_the_same_event_is_sent_once_per_request(): void
    {
        $this->withConsent();

        $pixel = app(Pixel::class);

        $this->assertTrue($pixel->send('ViewContent', ['content_ids' => ['1']], 'vc_1'));
        $this->assertFalse($pixel->send('ViewContent', ['content_ids' => ['1']], 'vc_1'));

        Http::assertSentCount(1);
    }

    public function test_test_event_code_is_attached_when_configured(): void
    {
        $this->withConsent();
        config(['services.facebook.test_event_code' => 'TEST123']);

        app(Pixel::class)->send('PageView', [], 'pv_2');

        Http::assertSent(fn ($r) => $r['test_event_code'] === 'TEST123');
    }

    /** Meta being down must never cost an order. */
    public function test_a_failure_is_swallowed(): void
    {
        request()->cookies->set('cookie_consent', 'all');
        $this->fakeMeta(500);

        $this->assertFalse(app(Pixel::class)->send('Purchase', [], 'pu_1'));
    }

    /**
     * A component can hand a payload straight to the browser half.
     *
     * The obvious way to write this call is to spread the payload, and the
     * obvious way is wrong: Livewire's dispatch() names its own first parameter
     * $event, so an "event" key arriving as a named argument is a fatal error.
     * Every pixel event in the shop goes out this way, and nothing else here
     * crosses the Livewire boundary — so it is tested at the boundary.
     */
    public function test_a_component_dispatches_the_payload_as_one_argument(): void
    {
        $this->withConsent();

        Livewire::test(PixelProbe::class)->assertDispatched('pixel', function ($name, $params) {
            $payload = $params[0];

            return $payload['event'] === 'AddToCart'
                && str_starts_with($payload['id'], 'atc_')
                && $payload['data']['content_ids'] === ['142'];
        });
    }

    protected function withConsent(): void
    {
        request()->cookies->set('cookie_consent', 'all');
        $this->fakeMeta();
    }
}

/** The smallest component that fires a pixel event, used by the test above. */
class PixelProbe extends Component
{
    public function mount(): void
    {
        $this->dispatch('pixel', app(Pixel::class)->addToCart([
            'id' => 142, 'brand' => 'NORDA', 'name' => 'Pro 14', 'price' => 4780.0, 'qty' => 1,
        ]));
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
