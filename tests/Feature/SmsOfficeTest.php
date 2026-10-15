<?php

namespace Tests\Feature;

use App\Services\Sender\SmsOffice;
use App\Support\Redact;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Text messages through smsoffice.ge.
 *
 * The gateway has one endpoint and no session, so there is little to get
 * wrong in the request — and three things that are easy to get wrong around
 * it, which is what these cover.
 *
 * The number: customers type "599 12 34 56" and the gateway reads that as an
 * unknown destination rather than as something to tidy up.
 *
 * The answer: smsoffice replies 200 and then says no in the body. A wrong
 * key, an unregistered sender name and an empty balance all arrive that way,
 * so reading the status code alone reports every one of them as delivered —
 * and the shop goes on believing the customer was told.
 *
 * The switch: a staging database holds real telephone numbers and the
 * gateway charges for each one.
 */
class SmsOfficeTest extends TestCase
{
    /* ------------------------------------------------------------------ the request */

    public function test_a_message_is_sent(): void
    {
        Http::fake(fn () => Http::response(['Success' => true, 'Message' => 'Success']));

        $this->assertTrue($this->sms()->send('599123456', 'გამარჯობა'));

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://smsoffice.ge/api/v2/send/')
            && $r['key'] === 'test-key'
            && $r['sender'] === 'IAPI'
            && $r['content'] === 'გამარჯობა');
    }

    /** The gateway wants 995XXXXXXXXX; people do not type it that way. */
    public function test_the_number_is_cleaned_up_first(): void
    {
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->sms()->send('599 12 34 56', 'კოდი');

        Http::assertSent(fn ($r) => $r['destination'] === '995599123456');
    }

    public function test_a_number_written_for_a_landline_keypad_is_read_too(): void
    {
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->sms()->send('(032) 212-10-28', 'კოდი');

        Http::assertSent(fn ($r) => $r['destination'] === '995322121028');
    }

    public function test_a_number_with_nothing_dialable_in_it_sends_nothing(): void
    {
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->assertFalse($this->sms()->send('—', 'კოდი'));

        Http::assertNothingSent();
    }

    public function test_an_empty_message_sends_nothing(): void
    {
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->assertFalse($this->sms()->send('599123456', '   '));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ the answer */

    /**
     * The one that matters.
     *
     * 200 with "Success": false is how every ordinary failure arrives, and
     * reporting it as sent is worse than failing — the shop stops looking.
     */
    public function test_a_refusal_dressed_as_a_200_is_not_a_sent_message(): void
    {
        Http::fake(fn () => Http::response([
            'Success' => false,
            'Message' => 'Invalid sender name',
        ]));

        $this->assertFalse($this->sms()->send('599123456', 'კოდი'));
    }

    public function test_an_http_error_is_a_failure(): void
    {
        Http::fake(fn () => Http::response('nope', 500));

        $this->assertFalse($this->sms()->send('599123456', 'კოდი'));
    }

    /** A gateway that cannot be reached must not take the page down with it. */
    public function test_a_dead_gateway_does_not_throw(): void
    {
        Http::fake(fn () => throw new \RuntimeException('could not connect'));

        $this->assertFalse($this->sms()->send('599123456', 'კოდი'));
    }

    /**
     * An answer in a shape we do not know is taken at face value.
     *
     * The status code already said yes, and refusing every receipt we cannot
     * read would mean sending nothing at all the day they change the format.
     */
    public function test_an_unfamiliar_answer_is_believed(): void
    {
        Http::fake(fn () => Http::response('OK'));

        $this->assertTrue($this->sms()->send('599123456', 'კოდი'));
    }

    /* ------------------------------------------------------------------ the switch */

    public function test_nothing_is_sent_while_the_gateway_is_off(): void
    {
        config(['smsoffice.enabled' => false]);
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->assertFalse($this->sms()->send('599123456', 'კოდი'));

        Http::assertNothingSent();
    }

    public function test_a_missing_key_counts_as_off(): void
    {
        config(['smsoffice.api_key' => null]);

        $this->assertFalse($this->sms()->enabled());
    }

    public function test_a_missing_sender_name_counts_as_off(): void
    {
        config(['smsoffice.sender' => null]);

        $this->assertFalse($this->sms()->enabled());
    }

    /** For testing against the real gateway without texting a customer. */
    public function test_every_message_can_be_redirected_to_one_number(): void
    {
        config(['smsoffice.redirect_to' => '555000111']);
        Http::fake(fn () => Http::response(['Success' => true]));

        $this->sms()->send('599123456', 'კოდი');

        Http::assertSent(fn ($r) => $r['destination'] === '995555000111');
    }

    /* ------------------------------------------------------------------ the log */

    /** A log of customers' telephone numbers is not a log, it is a list. */
    public function test_a_number_is_written_down_with_its_middle_taken_out(): void
    {
        $this->assertSame('995*****3456', Redact::phone('995599123456'));
    }

    public function test_a_short_number_keeps_almost_nothing(): void
    {
        $this->assertSame('****56', Redact::phone('123456'));
    }

    public function test_redacting_nothing_gives_nothing(): void
    {
        $this->assertSame('', Redact::phone(null));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'smsoffice.url' => 'https://smsoffice.ge/api/v2/send/',
            'smsoffice.api_key' => 'test-key',
            'smsoffice.sender' => 'IAPI',
            'smsoffice.enabled' => true,
            'smsoffice.timeout' => 5,
            'smsoffice.redirect_to' => null,
            'shop.phone_country' => '995',
        ]);
    }

    /** Built after the configuration, because it reads it in the constructor. */
    protected function sms(): SmsOffice
    {
        return new SmsOffice;
    }
}
