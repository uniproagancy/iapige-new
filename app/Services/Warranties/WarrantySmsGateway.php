<?php

namespace App\Services\Warranties;

use Illuminate\Support\Facades\Http;

class WarrantySmsGateway
{
    public function ready(): bool
    {
        return (bool) config('smsoffice.enabled')
            && filled(config('smsoffice.api_key')) && filled(config('smsoffice.sender'))
            && ! filled(config('smsoffice.redirect_to')) && $this->endpoint() !== null;
    }

    public function send(string $recipient, string $message, string $reference): array
    {
        if (! $this->ready()) {
            return ['status' => 'rejected', 'error' => 'gateway_disabled'];
        }
        try {
            // One attempt: retrying an uncertain response can send the same SMS twice.
            $response = Http::asForm()->acceptJson()->connectTimeout(5)
                ->timeout(max(5, min(30, (int) config('smsoffice.timeout', 10))))
                ->withOptions(['allow_redirects' => false])
                ->post($this->endpoint(), [
                    'key' => config('smsoffice.api_key'), 'sender' => config('smsoffice.sender'),
                    'destination' => $recipient, 'content' => $message, 'reference' => $reference,
                ]);
        } catch (\Throwable) {
            return ['status' => 'unknown', 'error' => 'gateway_connection_unknown'];
        }
        $body = $response->json();
        if ($response->successful() && is_array($body) && ($body['Success'] ?? null) === true
            && (int) ($body['ErrorCode'] ?? -1) === 0) {
            return ['status' => 'accepted', 'error' => null];
        }
        if (is_array($body) && ($body['Success'] ?? null) === false) {
            $code = filter_var($body['ErrorCode'] ?? null, FILTER_VALIDATE_INT);

            return ['status' => 'rejected', 'error' => $code === false ? 'gateway_refused' : 'gateway_code_'.$code];
        }

        // HTTP success alone does not prove acceptance, and acceptance is not delivery.
        return ['status' => 'unknown', 'error' => 'gateway_response_unknown'];
    }

    private function endpoint(): ?string
    {
        $parts = parse_url((string) config('smsoffice.url'));
        if (! is_array($parts) || ! in_array(strtolower($parts['host'] ?? ''), ['smsoffice.ge', 'www.smsoffice.ge'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || rtrim($parts['path'] ?? '', '/') !== '/api/v2/send') {
            return null;
        }

        return 'https://'.strtolower($parts['host']).'/api/v2/send/';
    }
}
