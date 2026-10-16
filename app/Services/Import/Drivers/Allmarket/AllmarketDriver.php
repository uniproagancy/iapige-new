<?php

namespace App\Services\Import\Drivers\Allmarket;

use App\Models\Supplier;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use DOMDocument;
use DOMXPath;

class AllmarketDriver implements SupplierDriver
{
    protected const MAX_SPEC_LENGTH = 120;

    protected AllmarketClient $client;

    public function __construct(public Supplier $supplier)
    {
        $this->client = new AllmarketClient($supplier->config ?? []);
    }

    public function ids(): iterable
    {

        $this->client->forget();

        return array_keys($this->client->feed());
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        $product = $this->client->product($externalId);

        return $product ? $this->toPayload($externalId, $product) : null;
    }

    protected function toPayload(string $code, array $data): ?ProductPayload
    {
        $locale = $this->supplier->config['locale'] ?? 'ka';
        $name = trim((string) ($data['title'] ?? ''));

        if ($name === '') {
            return null;
        }

        $retail = (float) ($data['rrp']['original'] ?? 0);
        $sale = (float) ($data['rrp']['discounted'] ?? 0);

        [$price, $old] = $sale > 0 && $sale < $retail ? [$sale, $retail] : [$retail, null];

        $description = (string) ($data['description'] ?? $data['descrption'] ?? '');

        $specs = $this->specs($description, $locale);

        $images = array_values(array_filter(
            (array) ($data['images'] ?? []),
            fn ($url) => is_string($url) && $url !== '',
        ));

        return new ProductPayload(
            externalId: $code,
            sku: $this->supplier->code.'-'.$code,
            costPrice: $price,
            oldCostPrice: $old,
            stock: (int) ($data['quantity'] ?? 0),
            brandName: $data['brand']['title'] ?? $this->valueOf($specs, ['ბრენდი', 'Brand']),
            categoryName: $data['categories'][0]['title'] ?? null,
            translations: [$locale => [
                'name' => $name,
                'description' => $description ?: null,
            ]],
            specs: $specs,
            images: $images,
        );
    }

    protected function specs(string $description, string $locale): array
    {
        if (trim($description) === '') {
            return [];
        }

        $pairs = str_contains($description, '<li')
            ? $this->pairsFromHtml($description)
            : $this->pairsFromText($description);

        $specs = [];

        foreach ($pairs as $name => $value) {
            if (! $this->usable($value)) {
                continue;
            }

            $specs[] = [
                'name' => $name,
                'value' => $value,
                'locale' => $locale,
                'key' => false,

                'filterable' => false,
                'color' => null,
            ];
        }

        return $specs;
    }

    protected function pairsFromHtml(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">'.$html);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $pairs = [];

        foreach ($xpath->query('//li') ?: [] as $item) {
            $bold = $xpath->query('.//strong | .//b', $item)?->item(0);

            if (! $bold) {

                $text = $this->clean($item->textContent);

                if (str_contains($text, ':')) {
                    [$label, $value] = array_map('trim', explode(':', $text, 2));

                    if ($label !== '' && $value !== '') {
                        $pairs[$label] = $value;
                    }
                }

                continue;
            }

            $value = $this->clean($bold->textContent);

            $label = $this->clean(str_replace($bold->textContent, '', $item->textContent));
            $label = rtrim($label, " \t:：-–");

            if ($label !== '' && $value !== '') {
                $pairs[$label] = $value;
            }
        }

        return $pairs;
    }

    protected function pairsFromText(string $description): array
    {
        $pairs = [];
        $heading = null;

        foreach (preg_split('/\r\n|\r|\n/', $description) as $line) {
            $line = trim(preg_replace('/^\s*[*•\-–]\s*/u', '', strip_tags($line)));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));

            if ($label === '') {
                continue;
            }

            if ($value === '') {
                $heading = $label;

                continue;
            }

            if ($heading && ! $this->looksLikeMeasurement($value)) {
                $heading = null;
            }

            $pairs[$heading ? "{$heading} ({$label})" : $label] = $value;
        }

        return $pairs;
    }

    protected function looksLikeMeasurement(string $value): bool
    {
        return (bool) preg_match('/\d+([.,]\d+)?\s*[x×хX]\s*\d/u', $value);
    }

    protected function usable(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= self::MAX_SPEC_LENGTH
            && ! in_array($value, ['-', '—', 'N/A', 'n/a'], true);
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function valueOf(array $specs, array $names): ?string
    {
        foreach ($specs as $spec) {
            foreach ($names as $name) {
                if (mb_strtolower($spec['name']) === mb_strtolower($name)) {
                    return $spec['value'];
                }
            }
        }

        return null;
    }
}
