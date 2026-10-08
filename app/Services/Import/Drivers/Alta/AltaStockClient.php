<?php

namespace App\Services\Import\Drivers\Alta;

use RuntimeException;
use SoapClient;

/**
 * The B2B price list: a SOAP endpoint that answers with every item the
 * supplier holds, and in what quantity.
 */
class AltaStockClient
{
    protected ?SoapClient $client = null;

    public function __construct(protected array $config = []) {}

    protected function client(): SoapClient
    {
        $wsdl = config('services.alta.wsdl');

        if (! $wsdl) {
            throw new RuntimeException('services.alta.wsdl is not configured — add the alta block to config/services.php.');
        }

        /*
         * A SOAP call with no timeout of its own falls back to PHP's
         * default_socket_timeout, which is a server setting we do not control
         * and which the WSDL fetch ignores entirely. An unresponsive endpoint
         * could therefore outlast the job that called it — and a job killed
         * mid-call leaves no exception, only a spent attempt. Every other
         * driver answers within the same budget; this one now does too.
         */
        $timeout = (int) ($this->config['timeout'] ?? config('shop.import_request_timeout', 30));

        return $this->client ??= new SoapClient($wsdl, [
            'trace' => 1,
            'exceptions' => true,
            'encoding' => 'UTF-8',
            'connection_timeout' => $timeout,
            'stream_context' => stream_context_create([
                'http' => ['timeout' => $timeout],
                'ssl' => ['timeout' => $timeout],
            ]),
        ]);
    }

    /**
     * @return array<int, array{external_id:string, quantity:int}>
     */
    public function items(string $item = ''): array
    {
        $response = $this->client()->GetPriceList([
            'user' => config('services.alta.user'),
            'password' => config('services.alta.password'),
            'item' => $item,
        ]);

        $items = $response->PriceList->items->item ?? [];

        if (! is_array($items)) {
            $items = [$items];
        }

        return array_values(array_filter(array_map(function ($row) {
            $id = trim((string) ($row->item ?? ''));

            return $id === '' ? null : [
                'external_id' => $id,
                'quantity' => $this->quantity($row->qty_text ?? null),
            ];
        }, $items)));
    }

    /**
     * The feed reports quantity as free text: "12", ">5", ">=10", "in stock".
     * Anything we cannot read as a number becomes a conservative 5.
     */
    public function quantity(?string $text): int
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return 0;
        }

        if (str_starts_with($text, '>=')) {
            return max((int) filter_var($text, FILTER_SANITIZE_NUMBER_INT), 10);
        }

        if (str_starts_with($text, '>')) {
            return (int) filter_var($text, FILTER_SANITIZE_NUMBER_INT);
        }

        if (is_numeric($text)) {
            return (int) $text;
        }

        if (preg_match('/out|not|unavailable|არ არის/iu', $text)) {
            return 0;
        }

        return 5;
    }
}
