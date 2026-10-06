<?php

namespace App\Support;

/**
 * Keeps credentials out of anything we write down.
 *
 * Several suppliers are reached by putting a token in the query string, which
 * is their API's design and not ours to change. The cost is that any client
 * error quotes the whole URL — and those messages go to the import log and to
 * the console, so a timeout was enough to write a live token to disk and print
 * it where somebody would copy it into a chat window.
 */
class Redact
{
    /** Query parameters whose value is a credential. */
    protected const SECRET_PARAMS = [
        'token', 'accessToken', 'access_token', 'cfClearance', 'cf_clearance',
        'apikey', 'api_key', 'key', 'secret', 'password',
    ];

    /** Cookies whose value is a credential. */
    protected const SECRET_COOKIES = [
        'zoommer-access_token', 'cf_clearance',
    ];

    /**
     * The same text with every credential replaced.
     *
     * Deliberately blunt: it keeps the parameter name, because knowing *which*
     * token a message was about is the useful half, and drops the value, which
     * is the half that must not be kept.
     */
    public static function secrets(?string $text): string
    {
        if (! $text) {
            return (string) $text;
        }

        foreach (self::SECRET_PARAMS as $param) {
            $text = (string) preg_replace(
                // ';' ends a value too, or a cookie's separator is eaten with it
                '/(\b'.preg_quote($param, '/').'=)[^&;\s"\'\]]+/i',
                '$1[redacted]',
                $text,
            );
        }

        foreach (self::SECRET_COOKIES as $cookie) {
            $text = (string) preg_replace(
                '/('.preg_quote($cookie, '/').'=)[^;\s"\']+/i',
                '$1[redacted]',
                $text,
            );
        }

        return $text;
    }
}
