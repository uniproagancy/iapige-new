<?php

namespace Tests\Unit;

use App\Support\Redact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Credentials must not survive into anything we write down.
 *
 * Several suppliers are reached by putting a token in the query string, so any
 * client error quotes the whole URL — and those messages go to the import log
 * and to the console. A plain connection timeout was enough to write a live
 * JWT to disk and print it where somebody would paste it into a chat window.
 */
class RedactTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_removes_the_value_and_keeps_the_name(string $input, string $expected): void
    {
        $this->assertSame($expected, Redact::secrets($input));
    }

    public static function cases(): array
    {
        return [
            'worker token in a url' => [
                'cURL error 28 for https://w.workers.dev?type=product&productId=3&token=eyJhbGciOiJSUzI1NiJ9.abc',
                'cURL error 28 for https://w.workers.dev?type=product&productId=3&token=[redacted]',
            ],
            'access token param' => [
                'https://w.workers.dev?type=image&url=https://cdn/x.jpg&accessToken=1%2BWTwEjmm',
                'https://w.workers.dev?type=image&url=https://cdn/x.jpg&accessToken=[redacted]',
            ],
            'cookie form' => [
                'Cookie: zoommer-cookie_agreed=true; zoommer-access_token=1%2BWTw; cf_clearance=nvOm6',
                'Cookie: zoommer-cookie_agreed=true; zoommer-access_token=[redacted]; cf_clearance=[redacted]',
            ],
            'several at once' => [
                '?token=aaa&accessToken=bbb&cfClearance=ccc',
                '?token=[redacted]&accessToken=[redacted]&cfClearance=[redacted]',
            ],
            'the parameter name survives, so the message still means something' => [
                'refused: token=secret',
                'refused: token=[redacted]',
            ],
            'nothing to hide is left alone' => [
                'Connection timed out after 10004 milliseconds',
                'Connection timed out after 10004 milliseconds',
            ],
            'an id is not a secret' => [
                '?type=product&productId=54500',
                '?type=product&productId=54500',
            ],
            'empty' => ['', ''],
        ];
    }

    public function test_null_is_tolerated(): void
    {
        $this->assertSame('', Redact::secrets(null));
    }

    /** The real message that leaked, end to end. */
    public function test_the_message_that_leaked_is_clean(): void
    {
        $leaked = 'cURL error 28: Connection timed out after 10012 milliseconds for '
            .'https://divine-king-feac.royal-sunset-e1c6.workers.dev?type=product&productId=3'
            .'&token=eyJhbGciOiJSUzI1NiIsImtpZCI6IkZBNjEwNzA1NDFDODNFQjNFMTQzODVDODA1Q0MwNjcyNEY1RjkyMjZSUzI1NiJ9.payload.sig';

        $clean = Redact::secrets($leaked);

        $this->assertStringNotContainsString('eyJhbGciOi', $clean);
        $this->assertStringContainsString('token=[redacted]', $clean);
        $this->assertStringContainsString('productId=3', $clean);
    }
}
