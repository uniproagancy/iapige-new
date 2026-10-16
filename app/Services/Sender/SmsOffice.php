<?php

namespace App\Services\Sender;

use App\Support\Phone;
use App\Support\Redact;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            return false;
        }

        $destination = Phone::digits($phoneNumber);

        if ($destination === null) {
            $this->log('no telephone number to send to', $phoneNumber);

            return false;
        }

        if ($redirect = Config::get('smsoffice.redirect_to')) {
            $destination = Phone::digits($redirect) ?? $destination;
        }

        if (! $this->enabled()) {
            $this->log('not sent: the gateway is switched off', $destination, ['text' => $text]);

            return false;
        }

        try {
            $response = Http::timeout((int) Config::get('smsoffice.timeout', 10))

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

                'body' => Redact::secrets(mb_substr($response->body(), 0, 500)),
            ]);

            return false;
        }

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

    protected function log(string $message, string $number, array $context = []): void
    {
        Log::channel('sms')->warning($message, ['to' => Redact::phone($number)] + $context);
    }
}
