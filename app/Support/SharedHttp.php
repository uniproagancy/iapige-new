<?php

namespace App\Support;

use GuzzleHttp\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * One HTTP connection, reused for the life of the worker.
 *
 * Http::get() builds a new Guzzle client for every call, and a new client
 * means a new TCP connection and a new TLS handshake. Against midea.ge that
 * handshake is two hundred milliseconds and the request itself is eight:
 *
 *     a fresh client each time   205 ms, every time
 *     one client, reused         201 ms once, then 7 ms
 *
 * Twenty-five times, and it is paid on every search and every photograph. A
 * product with a gallery of five costs a second and a half of handshakes and
 * forty milliseconds of actual work; nine thousand of them is the difference
 * between an afternoon and a few minutes.
 *
 * The client is kept per host, because a connection is only reusable to the
 * host it was opened to, and only for this process — a queue worker lives for
 * hours and imports thousands of products, which is exactly the case this is
 * for.
 */
class SharedHttp
{
    /** @var array<string, Client> */
    protected static array $clients = [];

    /**
     * A request that will reuse this host's connection.
     *
     * Used exactly like Http::, because it returns the same PendingRequest —
     * the only difference is which Guzzle client it sends through.
     */
    public static function to(string $url): PendingRequest
    {
        /*
         * Never in tests.
         *
         * Setting a client replaces the one Http::fake() would have stubbed,
         * so a faked test would quietly make the real request instead — which
         * it did, against midea.ge, until this line existed. The transport is
         * the only difference between the two paths, so the tests lose
         * nothing by taking the ordinary one.
         */
        if (app()->runningUnitTests()) {
            return Http::timeout(30);
        }

        return Http::setClient(self::clientFor($url));
    }

    /** Opened once per host and kept. */
    protected static function clientFor(string $url): Client
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'default';

        return self::$clients[$host] ??= new Client([
            /*
             * Keep-alive is the whole point, and it is the default — but a
             * host that answers "Connection: close" ends the connection
             * anyway, so this is a request, not a guarantee. Where it is
             * honoured the handshake happens once.
             */
            'headers' => ['Connection' => 'keep-alive'],

            // Windows PHP tries IPv6 first and waits out the timeout
            'force_ip_resolve' => 'v4',
        ]);
    }

    /**
     * Drops every open connection.
     *
     * For a long-lived worker that has moved on to another supplier, and for
     * tests, where a client held between cases would outlive its fake.
     */
    public static function flush(): void
    {
        self::$clients = [];
    }
}
