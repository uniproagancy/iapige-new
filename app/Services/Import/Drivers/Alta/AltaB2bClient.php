<?php

namespace App\Services\Import\Drivers\Alta;

use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use SoapClient;

/*
 | Alta's B2B price list, over SOAP.
 |
 | This is where an item code comes from. Everything else -- the photographs,
 | the description, the struck-through price -- is looked up on the shop front
 | afterwards, keyed by that code.
 */
class AltaB2bClient
{
    protected ?SoapClient $soap = null;

    public function __construct(protected array $config = []) {}

    /**
     * The whole price list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->ask('');
    }

    /**
     * One item, for refreshing a single product.
     */
    public function one(string $item): ?array
    {
        return $this->ask($item)[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function ask(string $item): array
    {
        $user = (string) ($this->config['user'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($user === '' || $password === '') {
            Log::channel('import')->warning('alta b2b has no credentials', [
                'hint' => 'set ALTA_B2B_USER and ALTA_B2B_PASSWORD',
            ]);

            return [];
        }

        try {
            $response = $this->soap()->GetPriceList([
                'user' => $user,
                'password' => $password,
                'item' => $item,
            ]);
        } catch (\Throwable $e) {
            Log::channel('import')->warning('alta b2b refused', [
                'item' => $item ?: '(all)',
                'error' => Redact::secrets($e->getMessage()),
            ]);

            return [];
        }

        $items = $response->PriceList->items->item ?? null;

        if ($items === null) {
            /* An account without a price list answers with an empty envelope
               rather than an error, which is how a dead login looks. */
            Log::channel('import')->warning('alta b2b answered with no items', [
                'item' => $item ?: '(all)',
                'user' => $user,
            ]);

            return [];
        }

        $rows = [];

        foreach (is_array($items) ? $items : [$items] as $row) {
            $built = $this->row($row);

            if ($built) {
                $rows[] = $built;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function row(object $item): ?array
    {
        $code = trim((string) ($item->item ?? ''));

        if ($code === '') {
            return null;
        }

        return [
            'item' => $code,
            'model' => trim((string) ($item->item_model ?? '')) ?: null,
            'name' => $this->name((string) ($item->item_full_name ?? '')),
            'quantity' => $this->quantity($item->qty_text ?? null),
            'price' => (float) ($item->price_unit ?? 0),
            'brand' => trim((string) ($item->brand ?? '')) ?: null,
            'category' => trim((string) ($item->category ?? '')) ?: null,

            /* Grams, because that is what a payload carries. */
            'weight' => ($kg = (float) ($item->weight_kg ?? 0)) > 0 ? (int) round($kg * 1000) : null,
        ];
    }

    /*
     | Every line of the current list holds a plain number, but the field is
     | text and has carried ">=5" and "in stock" in the past, so a number is
     | read out of whatever arrives rather than assumed.
     */
    public function quantity(mixed $text): int
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return 0;
        }

        if (preg_match('/\d+/', $text, $m)) {
            return max(0, (int) $m[0]);
        }

        /* Words, with no number beside them: present but uncounted. */
        return preg_match('/out|not|unavailable|არ/u', $text) ? 0 : 1;
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /*
     | Some of the list's names open with the brand twice over, as in
     | "Lenovo/ Lenovo ThinkCentre neo 55s". Only that exact repetition is
     | dropped: a slash means something in most of the other names, where it
     | separates one figure from another ("12GB/128GB").
     */
    protected function name(string $value): string
    {
        $clean = $this->clean($value);

        if (! preg_match('#^(\S+)/\s*(\S.*)$#u', $clean, $m)) {
            return $clean;
        }

        return str_starts_with(mb_strtolower($m[2]), mb_strtolower($m[1]).' ') ? $m[2] : $clean;
    }

    protected function soap(): SoapClient
    {
        return $this->soap ??= new SoapClient(
            (string) ($this->config['wsdl'] ?? 'http://extra.alta.com.ge/b2b/b2bEWS?WSDL'),
            [
                'trace' => false,
                'exceptions' => true,
                'encoding' => 'UTF-8',
                'connection_timeout' => (int) ($this->config['timeout'] ?? 30),
                'cache_wsdl' => WSDL_CACHE_MEMORY,
            ],
        );
    }
}
