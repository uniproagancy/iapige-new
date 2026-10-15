<?php

namespace App\Services\Sender;

use App\Support\Phone;
use App\Support\Redact;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text messages, through smsoffice.ge.
 *
 * One call does the whole thing — this is a gateway with a single endpoint
 * and no session — so send() is the whole surface and whatever calls it only
 * has to know a number and a sentence.
 *
 * It answers true or false and never throws. A text message is something the
 * shop does alongside the real work: it is sent while a customer is waiting
 * for a page or while an operator is saving an order, and neither of those
 * should fail because a gateway is having a bad afternoon. What it does not
 * do is fail quietly — every refusal is written to the sms log with the
 * gateway's own answer, because a message nobody received and nobody
 * recorded is indistinguishable from one that was never sent.
 */
class SmsOffice
{
    protected string $url;

    protected ?string $apiKey;

    protected ?string $sender;

    public function __construct()
    {
        $this->url = (string) Config::get('smsoffice.url');
        $this->apiKey = Config::get('smsoffice.api_key');
        $this->sender = Config::get('smsoffice.sender');
    }

    /**
     * Whether a message sent now would actually go anywhere.
     *
     * Worth asking before building one: the gateway is off in every
     * environment but production, and a caller that checks can say "not sent"
     * rather than recording a message it never made.
     */
    public function enabled(): bool
    {
        return (bool) Config::get('smsoffice.enabled')
            && $this->url !== ''
            && filled($this->apiKey)
            && filled($this->sender);
    }

    public function send(string $phoneNumber, string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;   // the gateway charges for an empty message too
        }

        /*
         * The number as the gateway wants it: 995XXXXXXXXX, no plus, no
         * spaces. Customers type "599 12 34 56" and the gateway takes that
         * as an unknown destination rather than as a number to clean up.
         */
        $destination = Phone::digits($phoneNumber);

        if ($destination === null) {
            $this->log('no telephone number to send to', $phoneNumber);

            return false;
        }

        // for testing against the real gateway without texting a real customer
        if ($redirect = Config::get('smsoffice.redirect_to')) {
            $destination = Phone::digits($redirect) ?? $destination;
        }

        if (! $this->enabled()) {
            $this->log('not sent: the gateway is switched off', $destination, ['text' => $text]);

            return false;
        }

        try {
            $response = Http::timeout((int) Config::get('smsoffice.timeout', 10))
                /*
                 * Two attempts, not more. The gateway answering slowly is
                 * worth a second try; it answering "no" is not, and a third
                 * attempt only spends the customer's wait.
                 */
                ->retry(2, 500, throw: false)
                ->get($this->url, [
                    'key' => $this->apiKey,
                    'destination' => $destination,
                    'sender' => $this->sender,
                    'content' => $text,
                ]);
        } catch (\Throwable $e) {
            $this->log('the gateway could not be reached', $destination, ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            $this->log('the gateway refused', $destination, [
                'status' => $response->status(),
                // their error pages quote the request URL, and the key is in it
                'body' => Redact::secrets(mb_substr($response->body(), 0, 500)),
            ]);

            return false;
        }

        /*
         * A 200 is not a sent message.
         *
         * smsoffice answers 200 and then says no in the body — a wrong key,
         * an unregistered sender name, an empty balance all come back that
         * way. Reading only the status code reports every one of those as
         * delivered, which is the worst answer available: the shop believes
         * the customer was told.
         */
        if (($refusal = $this->refusal($response->json())) !== null) {
            $this->log('the gateway answered no', $destination, ['reason' => $refusal]);

            return false;
        }

        Log::channel('sms')->info('sent', [
            'to' => Redact::phone($destination),
            'length' => mb_strlen($text),
        ]);

        return true;
    }

    /**
     * What the gateway said was wrong, or null if it said nothing was.
     *
     * Their answer is {"Success":true,"Message":"...","ErrorCode":0}. An
     * answer in any other shape is taken at face value — the status code
     * already said yes, and refusing a message we cannot read the receipt
     * for would mean never sending one if they ever change the format.
     */
    protected function refusal(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $success = $body['Success'] ?? $body['success'] ?? null;

        if ($success === null || $success) {
            return null;
        }

        return (string) ($body['Message'] ?? $body['message'] ?? 'the gateway gave no reason');
    }

    /** @param  array<string, mixed>  $context */
    protected function log(string $message, string $number, array $context = []): void
    {
        Log::channel('sms')->warning($message, ['to' => Redact::phone($number)] + $context);
    }
}
