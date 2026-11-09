<?php

namespace App\Services\Import\Drivers\Midea;

use App\Services\Import\RoomArea;
use App\Support\Figure;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MideaClient
{
    public function __construct(protected array $config = []) {}

    public function searchByName(string $name): ?array
    {
        $wanted = $this->key($name);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->keywords($name) as $keyword) {
            foreach ($this->search($keyword) as $product) {
                if ($this->matches($wanted, (string) ($product['model_number'] ?? ''))) {
                    return $this->take($product, $wanted);
                }
            }
        }

        return null;
    }

    protected function keywords(string $name): array
    {
        $name = trim($name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 2) {
            return [$name];
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $brand = mb_strtolower((string) ($this->config['brand'] ?? 'midea'));

        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 5 && mb_strtolower($w) !== $brand);

        return array_merge([$name], array_values($words));
    }

    public function search(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $base = $this->base();

        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)

            ->withOptions(['force_ip_resolve' => 'v4'])

            ->retry(2, 500, throw: false)
            ->asForm()
            ->withHeaders([
                'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $base.'/ka/',
            ])
            ->post($base.'/ka/search', ['keyword' => $keyword]);

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        return (int) ($body['status'] ?? 0) === 1 && is_array($body['products'] ?? null)
            ? $body['products']
            : [];
    }

    protected function take(array $product, ?string $wanted = null): array
    {
        $price = Figure::amount($product['price'] ?? null, Figure::DOT);
        $sale = Figure::amount($product['sale_price'] ?? null, Figure::DOT);
        $metrics = $wanted === null || $wanted === $this->key((string) ($product['model_number'] ?? ''))
            ? $this->technicalMetrics($product) : [];

        return [
            'sku' => trim((string) ($product['model_number'] ?? $product['id'] ?? '')),
            'name' => $this->clean((string) ($product['title'] ?? '')),
            'brand' => $this->config['brand'] ?? 'Midea',
            'price' => $price,

            'sale_price' => $sale > 0 && $sale < $price ? $sale : null,
            'description' => $this->clean((string) ($product['text'] ?? $product['short_text'] ?? '')) ?: null,
            'images' => $this->images($product),
            'in_stock' => (string) ($product['in_stock'] ?? '1') === '1',
            'weight' => (int) ($product['weight'] ?? 0) ?: null,
            'room_area' => $metrics['room_area'] ?? null,
            'wash_load' => $metrics['wash_load'] ?? null,
            'total_volume' => $metrics['total_volume'] ?? null,
        ];
    }

    protected function technicalMetrics(array $product): array
    {
        $url = trim((string) ($product['link'] ?? ''));
        $parts = parse_url($url);
        $base = parse_url($this->base());
        $model = $this->key((string) ($product['model_number'] ?? ''));

        // Only enrich the matched product from its supplier-provided, same-origin link.
        if (! is_array($parts) || ! is_array($base) || $model === ''
            || ($parts['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '')
            || ($parts['port'] ?? null) !== ($base['port'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || ! preg_match('~\A/ka/+(?:product)/[^/]+/[0-9]+/?\z~', $parts['path'] ?? '')) {
            return [];
        }

        try {
            $response = Http::timeout($this->config['timeout'] ?? 30)
                ->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4', 'allow_redirects' => false])
                ->withHeaders([
                    'User-Agent' => config('services.midea.user_agent', 'Mozilla/5.0'),
                    'Accept-Language' => 'ka',
                ])
                ->get($url);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $html = $response->body();

        if (strlen($html) > 2_000_000) {
            return [];
        }

        // The supplier currently omits ">" between the table class and first row.
        $html = preg_replace('~(<table\b[^>]*?)(?=<tr\b)~i', '$1>', $html);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $values = ['room_area' => [], 'wash_load' => [], 'total_volume' => []];
        $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " specs-table ")]');
        $pageMatches = $tables->length === 1 && $this->pageMatches($xpath, $product, $parts['path']);
        $fridge = preg_match('~მაცივარ|საყინულე~u', mb_strtolower($this->clean((string) ($product['title'] ?? '')))) === 1;

        foreach ($tables as $table) {
            $models = [];
            $metrics = ['room_area' => [], 'wash_load' => [], 'total_volume' => []];

            foreach ($xpath->query('.//tr', $table) as $row) {
                $cells = $xpath->query('./td', $row);

                if ($cells->length < 2) {
                    continue;
                }

                $name = mb_strtolower($this->clean($cells->item(0)->textContent));
                $value = $this->clean($cells->item(1)->textContent);
                $unit = null;
                $valueColumn = 1;

                if (in_array($name, ['მოდელის სახელი', 'მოდელი', 'model', 'model name'], true)) {
                    $models[$this->key($value)] = true;
                }

                // Some supplier tables use a separate units column before the value.
                if ($cells->length >= 3 && ($this->unitKind($value) !== null || RoomArea::normalize('1', $value) !== null)) {
                    $unit = $value;
                    $value = $this->clean($cells->item(2)->textContent);
                    $valueColumn = 2;
                } elseif ($cells->length >= 3 && $value === '' && $this->areaValue($name, '1') !== null) {
                    // A blank units column is safe only when the area header declares m².
                    $value = $this->clean($cells->item(2)->textContent);
                    $valueColumn = 2;
                }

                $extra = false;

                for ($index = $valueColumn + 1; $index < $cells->length; $index++) {
                    $extra = $extra || $this->clean($cells->item($index)->textContent) !== '';
                }

                if ($extra) {
                    continue;
                }

                foreach ([
                    'room_area' => $this->areaValue($name, $value, $unit),
                    'wash_load' => $this->capacityValue($name, $value, 'kg', $unit),
                    'total_volume' => $this->capacityValue($name, $value, 'l', $unit, $fridge),
                ] as $kind => $normalized) {
                    if ($normalized !== null) {
                        $metrics[$kind][$normalized] = true;
                    }
                }
            }

            // A conflicting table model cannot fall back to the page's title.
            $matchedModel = $models === [] ? $pageMatches : count($models) === 1 && isset($models[$model]);

            if ($matchedModel) {
                foreach ($metrics as $kind => $candidates) {
                    $values[$kind] += $candidates;
                }
            }
        }

        return array_map(fn (array $candidates) => count($candidates) === 1 ? array_key_first($candidates) : null, $values);
    }

    protected function pageMatches(DOMXPath $xpath, array $product, string $path): bool
    {
        $title = $xpath->evaluate('string(//head/meta[@property="og:title"]/@content)');
        $title = explode('|', $this->clean($title))[0];
        $expected = $this->key((string) ($product['title'] ?? ''));
        $model = $this->key((string) ($product['model_number'] ?? ''));
        $canonical = parse_url($xpath->evaluate('string(//head/link[@rel="canonical"]/@href)'));
        $base = parse_url($this->base());

        return $expected !== '' && str_contains($expected, $model)
            && $this->key($title) === $expected
            && is_array($canonical) && is_array($base)
            && ($canonical['scheme'] ?? '') === ($base['scheme'] ?? '')
            && strtolower($canonical['host'] ?? '') === strtolower($base['host'] ?? '')
            && ($canonical['port'] ?? null) === ($base['port'] ?? null)
            && ! isset($canonical['user']) && ! isset($canonical['pass'])
            && rtrim(preg_replace('~/+~', '/', $canonical['path'] ?? ''), '/') === rtrim(preg_replace('~/+~', '/', $path), '/');
    }

    protected function areaValue(string $name, string $value, ?string $columnUnit = null): ?string
    {
        $name = str_replace(['²', '^2'], '2', trim($name, " \t\n\r\0\x0B:"));
        $unit = null;

        if (preg_match('~\A(?<name>რეკომენდირებული ფართი|რეკომენდირებული ფართობი|რეკომენდებული ფართი)\s*\((?:გაგრილება|გათბობა)(?:\s*/\s*(?:გაგრილება|გათბობა))?\)\s*,?\s*(?<unit>[მmм]2|კვ\.?\s*მ\.?)\z~u', $name, $parts)
            || preg_match('~\A(?<name>.+?)(?:,\s*|\s*\()(?<unit>[მmм]2|კვ\.?\s*მ\.?)\)?\z~u', $name, $parts)) {
            $name = trim($parts['name']);
            $unit = $parts['unit'];
        }

        if ($columnUnit !== null && RoomArea::normalize('1', $columnUnit) === null) {
            return null;
        }

        if (! in_array($name, [
            'რეკომენდირებული ფართი', 'რეკომენდირებული ფართობი', 'რეკომენდებული ფართი',
            'სამუშაო ფართობი', 'გათბობის ფართობი', 'გათბობა/გაგრილების ფართობი',
            'გასათბობი ფართობი', 'ფართობი', 'ფართი',
        ], true)) {
            return null;
        }

        return RoomArea::normalize($value, $unit ?? $columnUnit);
    }

    protected function capacityValue(string $name, string $value, string $kind, ?string $columnUnit, bool $fridge = false): ?string
    {
        $name = trim($name, " \t\n\r\0\x0B:");
        $labelUnit = null;

        if (preg_match('~\A(?<name>.+?)(?:,\s*|\s*\()(?<unit>[^(),]+)\)?\z~u', $name, $parts)
            && $this->unitKind($parts['unit']) !== null) {
            $name = trim($parts['name']);
            $labelUnit = $parts['unit'];
        }

        $names = $kind === 'kg' ? [
            'მაქსიმალური ჩატვირთვა', 'მაქსიმალური ჩატვირთვის მოცულობა',
            'რეცხვის მოცულობა', 'რეცხვის ტევადობა', 'რეცხვის მაქსიმალური ჩატვირთვა',
        ] : [
            'მთლიანი მოცულობა', 'ჯამური მოცულობა', 'საერთო მოცულობა',
            'მთლიანი სასარგებლო მოცულობა', 'საერთო სასარგებლო მოცულობა',
            'საერთო ნეტო მოცულობა', 'მთლიანი ნეტო მოცულობა',
            'total volume', 'total net volume', 'net total volume',
        ];

        if (! in_array($name, $names, true)) {
            return null;
        }

        foreach ([$labelUnit, $columnUnit] as $unit) {
            if ($unit !== null && $this->unitKind($unit) !== $kind) {
                return null;
            }
        }

        // Litres are the configured unit of an explicitly identified fridge's TOTAL volume.
        // They do not apply to generic capacity, bottle counts or a single compartment.
        $defaultUnit = $kind === 'l' && $fridge ? 'l' : null;

        return self::normalizeCapacity($value, $kind, $labelUnit ?? $columnUnit ?? $defaultUnit);
    }

    public static function normalizeCapacity(string $value, string $kind, ?string $unit = null): ?string
    {
        if (! in_array($kind, ['kg', 'l'], true)
            || ! preg_match('~\A\s*(?<number>[0-9]{1,6}(?:[.,][0-9]{1,3})?)\s*(?<unit>[^0-9]*)\s*\z~u', $value, $parts)) {
            return null;
        }

        $hasUnit = false;

        foreach ([$parts['unit'], $unit] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            if (self::unitKind($candidate) !== $kind) {
                return null;
            }

            $hasUnit = true;
        }

        $number = (float) str_replace(',', '.', $parts['number']);

        if (! $hasUnit || $number <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.').' '.($kind === 'kg' ? 'კგ' : 'ლ');
    }

    protected static function unitKind(string $unit): ?string
    {
        $unit = mb_strtolower(trim($unit, " \t\n\r\0\x0B."));

        if (in_array($unit, ['kg', 'კგ', 'კილოგრამი', 'კილოგრამები', 'kilogram', 'kilograms'], true)) {
            return 'kg';
        }

        return in_array($unit, ['l', 'ლ', 'ლიტრი', 'ლიტრები', 'liter', 'liters', 'litre', 'litres'], true) ? 'l' : null;
    }

    protected function matches(string $wanted, string $found): bool
    {
        $found = $this->key($found);

        return $found !== '' && (str_contains($found, $wanted) || str_contains($wanted, $found));
    }

    protected function images(array $product): array
    {
        $path = '/'.trim((string) config('services.midea.image_path', '/uploads/products/'), '/').'/';
        $files = [];

        if ($main = trim((string) ($product['image'] ?? ''))) {
            $files[] = $main;
        }

        $gallery = $product['gallery'] ?? null;
        $gallery = is_string($gallery) ? json_decode($gallery, true) : $gallery;

        foreach ((array) $gallery as $file) {
            if (is_string($file) && trim($file) !== '') {
                $files[] = trim($file);
            }
        }

        return array_values(array_unique(array_map(
            fn (string $file) => $this->base().$path.ltrim($file, '/'),
            $files,
        )));
    }

    protected function base(): string
    {
        return rtrim((string) config('services.midea.base_url', 'https://www.midea.ge'), '/');
    }

    protected function key(string $model): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $model));
    }

    protected function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            preg_replace('#<br\s*/?>#i', ' ', $value),
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
