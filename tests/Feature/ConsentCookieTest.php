<?php

namespace Tests\Feature;

use App\Services\Facebook\Pixel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The consent cookie has to survive the middleware.
 *
 * The cookie banner writes it from JavaScript, which produces a plain value.
 * EncryptCookies could not decrypt that and dropped it from the request, so
 * the server read no consent from anybody and sent not one event — while the
 * browser half, reading document.cookie itself, carried on working. The two
 * halves disagreed about whether consent had been given, and only the half
 * an ad blocker removes was running.
 *
 * Driven through a real request on purpose. pixel:test sets the cookie
 * straight onto the request object, which is precisely the step that hid
 * this: the command passed while the site sent nothing.
 */
class ConsentCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.pixel_id' => '123456',
            'services.facebook.access_token' => 'token',
            'services.facebook.test_event_code' => null,
        ]);

        Route::middleware('web')->get('/_consent', fn (Pixel $pixel) => [
            'cookie' => request()->cookie('cookie_consent'),
            'consented' => $pixel->consented(),
            'enabled' => $pixel->enabled(),
        ]);
    }

    /** A cookie the browser wrote, exactly as the banner writes it. */
    public function test_the_server_reads_the_consent_the_browser_gave(): void
    {
        $response = $this->withUnencryptedCookie('cookie_consent', 'all')->get('/_consent');

        $response->assertOk();
        $this->assertSame('all', $response->json('cookie'));
        $this->assertTrue($response->json('consented'));
        $this->assertTrue($response->json('enabled'));
    }

    /** "Necessary only" has to keep meaning exactly that. */
    public function test_declining_still_switches_the_pixel_off(): void
    {
        $response = $this->withUnencryptedCookie('cookie_consent', 'necessary')->get('/_consent');

        $this->assertFalse($response->json('consented'));
        $this->assertFalse($response->json('enabled'));
    }

    public function test_no_cookie_is_no_consent(): void
    {
        $this->assertFalse($this->get('/_consent')->json('consented'));
    }

    /**
     * The exclusion itself, so nobody quietly puts it back.
     *
     * Read off the middleware's own static list: except() is a setter, not a
     * getter, and this is the list it writes into.
     */
    public function test_the_consent_cookie_is_excluded_from_encryption(): void
    {
        $never = new \ReflectionProperty(EncryptCookies::class, 'neverEncrypt');
        $never->setAccessible(true);

        $this->assertContains('cookie_consent', $never->getValue());
    }

    /**
     * And the whole point of it: an event actually leaves the server.
     *
     * This is what the site could not do, however well the console test ran.
     */
    public function test_an_event_leaves_the_server_on_a_real_request(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        Route::middleware('web')->get('/_fire', function (Pixel $pixel) {
            return ['sent' => $pixel->send('ViewContent', ['content_ids' => ['1']])];
        });

        $this->assertTrue($this->withUnencryptedCookie('cookie_consent', 'all')->get('/_fire')->json('sent'));

        Http::assertSent(fn ($request) => $request->data()['data'][0]['event_name'] === 'ViewContent');
    }

    /** Without consent nothing reaches Meta, whatever the component asks for. */
    public function test_nothing_leaves_without_consent(): void
    {
        Http::fake();

        Route::middleware('web')->get('/_fire', fn (Pixel $pixel) => ['sent' => $pixel->send('ViewContent')]);

        $this->assertFalse($this->get('/_fire')->json('sent'));

        Http::assertNothingSent();
    }
}
