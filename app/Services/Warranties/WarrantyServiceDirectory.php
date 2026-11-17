<?php

namespace App\Services\Warranties;

use App\Models\OrderDeliveryItem;
use Illuminate\Validation\ValidationException;

/** Read-only supplier routing. No product/price/stock writes and no inferred warranty periods. */
class WarrantyServiceDirectory
{
    private ?array $directory = null;

    public function supplierName(?OrderDeliveryItem $item): ?string
    {
        if (! $item) { return null; }
        $item->loadMissing('supplier');
        // Owner-confirmed legacy supplier identifier: this is the Global/Franko feed, not MetroMart retail.
        if ((int) $item->supplier_id === 9 && $item->supplier?->code === 'metromart') {
            return 'Global / Franko';
        }
        if ((int) $item->supplier_id === 6 && $item->supplier?->code === 'ingco') {
            return 'ნოვა / Nova';
        }
        return WarrantySupplierIdentity::displayName($item->supplier) ?: $item->supplier_name;
    }

    public function all(): array
    {
        if ($this->directory !== null) {
            return $this->directory;
        }
        try {
            $data = json_decode(file_get_contents(resource_path('warranty/service-directory.json')), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['service' => __('warranty.errors.directory_invalid')]);
        }
        if (($data['schema'] ?? null) !== 'iapi.warranty.service-directory.v1' || ! is_array($data['suppliers'] ?? null)) {
            throw ValidationException::withMessages(['service' => __('warranty.errors.directory_invalid')]);
        }

        return $this->directory = $data;
    }

    public function resolve(?OrderDeliveryItem $item): ?array
    {
        if (! $item?->supplier_id) {
            return null;
        }
        $item->loadMissing(['supplier', 'product.brand', 'product.category']);
        if (! $item->supplier) {
            return null;
        }
        $supplier = collect($this->all()['suppliers'])->first(fn ($row) => (int) $row['id'] === (int) $item->supplier_id
            && $row['code'] === $item->supplier->code);
        if (! $supplier || ($supplier['excluded'] ?? false)) {
            return null;
        }
        $brand = $this->name($item->product?->brand?->name);
        $categories = [];
        $categoryIds = [];
        $node = $item->product?->category;
        for ($depth = 0; $node && $depth < 16 && ! in_array((int) $node->id, $categoryIds, true); $depth++) {
            $categoryIds[] = (int) $node->id;
            if (filled($node->name)) {
                $categories[] = $this->category($node->name);
            }
            $node = $node->parent;
        }
        $matches = [];
        foreach ($supplier['routes'] as $route) {
            $brands = array_map($this->name(...), $route['brands'] ?? []);
            $excludedBrands = array_map($this->name(...), $route['exclude_brands'] ?? []);
            // Supplier-wide intake is allowed only when its official source explicitly covers all supplied goods.
            // Brand-specific providers still require an exact known brand; no fuzzy matching or offer fallback.
            if (! ($route['routing_confirmed'] ?? false)
                || (! ($route['all_brands'] ?? false) && ($brand === '' || ! in_array($brand, $brands, true)))
                || in_array($brand, $excludedBrands, true)
                || ($excludedBrands !== [] && $brand === '')) {
                continue;
            }
            $include = array_map($this->category(...), $route['category_names'] ?? []);
            $exclude = array_map($this->category(...), $route['exclude_category_names'] ?? []);
            $ids = array_map('intval', $route['category_ids'] ?? []);
            if (($include !== [] || $exclude !== [] || $ids !== []) && $categories === [] && $categoryIds === []) {
                continue;
            }
            if (($include !== [] && ! array_intersect($include, $categories))
                || ($ids !== [] && ! array_intersect($ids, $categoryIds))
                || array_intersect($exclude, $categories)) {
                continue;
            }
            // An unknown category cannot safely satisfy a negative category restriction.
            if ($exclude !== [] && $categories === []) {
                continue;
            }
            $matches[] = $route;
        }
        // No cheapest-offer fallback, guessed category, fuzzy brand, or ambiguous route.
        if (count($matches) !== 1) { return null; }
        $resolved = $matches[0];
        // Only catalog categories from this actual delivery item may select a category-specific clause.
        $resolved['_resolved_category_names'] = $categories;
        return $resolved;
    }

    /** Source-backed preparation text. This never approves a policy or changes an issued snapshot. */
    public function officialTerms(?array $route): ?array
    {
        $terms = $route['official_terms'] ?? null;
        if (! is_array($terms) || ($terms['confirmed'] ?? false) !== true
            || ! in_array($terms['status'] ?? null, ['substantive', 'partial'], true)) {
            return null;
        }
        $out = ['status' => $terms['status']];
        foreach (['supplier_name', 'scope_notice'] as $key) {
            $out[$key] = is_string($terms[$key] ?? null) ? mb_substr(trim($terms[$key]), 0, 3000) : null;
        }
        $hasText = false;
        foreach (['coverage', 'exclusions', 'customer_duties', 'claims', 'component_terms', 'missing_fields'] as $key) {
            $out[$key] = [];
            foreach (is_array($terms[$key] ?? null) ? array_slice($terms[$key], 0, 30) : [] as $text) {
                if (is_string($text) && trim($text) !== '') {
                    $out[$key][] = mb_substr(trim($text), 0, 5000);
                }
            }
            if ($key !== 'missing_fields' && $out[$key] !== []) { $hasText = true; }
        }
        $categories = $route['_resolved_category_names'] ?? [];
        foreach (is_array($terms['conditional_sections'] ?? null) ? $terms['conditional_sections'] : [] as $section) {
            if (! is_array($section) || ! is_array($section['category_names'] ?? null)
                || ! in_array($section['field'] ?? null, ['coverage', 'exclusions', 'customer_duties', 'claims', 'component_terms'], true)
                || ! is_string($section['text'] ?? null) || trim($section['text']) === '') { continue; }
            $names = array_map(fn ($name) => is_string($name) ? $this->category($name) : '', $section['category_names']);
            if ($categories !== [] && array_intersect($names, $categories)) {
                $out[$section['field']][] = mb_substr(trim($section['text']), 0, 5000);
                $hasText = true;
            }
        }
        $out['sources'] = [];
        foreach (is_array($terms['sources'] ?? null) ? array_slice($terms['sources'], 0, 15) : [] as $source) {
            $url = is_array($source) ? ($source['url'] ?? null) : null;
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null) { continue; }
            $out['sources'][] = ['url' => $url,
                'title' => is_string($source['title'] ?? null) ? mb_substr($source['title'], 0, 500) : parse_url($url, PHP_URL_HOST),
                'checked_on' => is_string($source['checked_on'] ?? null) ? mb_substr($source['checked_on'], 0, 10) : null];
        }

        return $hasText && $out['sources'] !== [] ? $out : null;
    }

    private function name(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name ?? '')));
    }

    private function category(string $name): string
    {
        $name = $this->name($name);

        return in_array($name, ['ტელევიზორი', 'ტელევიზორები', 'ტელევიზორები და აქსესუარები', 'tv', 'television', 'televisions'], true)
            ? 'tv' : $name;
    }
}
