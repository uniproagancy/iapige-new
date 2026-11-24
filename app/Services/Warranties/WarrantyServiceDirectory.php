<?php

namespace App\Services\Warranties;

use App\Models\OrderDeliveryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Read-only supplier routing. No product/price/stock writes and no inferred warranty periods. */
class WarrantyServiceDirectory
{
    private ?array $directory = null;

    public function supplierLegalName(?OrderDeliveryItem $item): ?string
    {
        if (! $item?->supplier_id) { return null; }
        $item->loadMissing('supplier');
        if (! $item->supplier || (int) $item->supplier->id !== (int) $item->supplier_id) { return null; }
        return $this->legalNameForSupplier((int) $item->supplier_id, (string) $item->supplier->code);
    }

    public function legalNameForSupplier(int $supplierId, string $supplierCode): ?string
    {
        return app(WarrantySupplierProfiles::class)->legalName($supplierId, $supplierCode);
    }

    public function supplierName(?OrderDeliveryItem $item): ?string
    {
        if (! $item) { return null; }
        $item->loadMissing('supplier');
        // Owner-confirmed company behind the Global/Franko feed; the legacy import ID/code stay unchanged.
        if ((int) $item->supplier_id === 9 && $item->supplier?->code === 'metromart') {
            return 'შპს „შამი“';
        }
        if ((int) $item->supplier_id === 6 && $item->supplier?->code === 'ingco') {
            return 'ნოვა / Nova';
        }
        return WarrantySupplierIdentity::displayName($item->supplier) ?: $item->supplier_name;
    }

    public function all(): array
    {
        return app(WarrantySupplierProfiles::class)->overlay($this->base());
    }

    /** Reviewed routes plus blank intake for genuinely new registered suppliers, before owner overlays. */
    public function base(): array
    {
        if ($this->directory !== null) {
            return app(WarrantyCustomServiceRoutes::class)->append($this->registeredSuppliers($this->directory));
        }
        try {
            $data = json_decode(file_get_contents(resource_path('warranty/service-directory.json')), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['service' => __('warranty.errors.directory_invalid')]);
        }
        if (($data['schema'] ?? null) !== 'iapi.warranty.service-directory.v1' || ! is_array($data['suppliers'] ?? null)) {
            throw ValidationException::withMessages(['service' => __('warranty.errors.directory_invalid')]);
        }

        $this->directory = $data;
        return app(WarrantyCustomServiceRoutes::class)->append($this->registeredSuppliers($data));
    }

    private function registeredSuppliers(array $directory): array
    {
        if (! Schema::hasTable('suppliers')) { return $directory; }
        $ids = []; $codes = [];
        foreach ($directory['suppliers'] as $supplier) {
            $ids[] = (int) $supplier['id']; $codes[] = $supplier['code'];
        }
        // Unlinked research is not authority to guess a new supplier's brand or service scope.
        foreach ($directory['unlinked_suppliers'] ?? [] as $supplier) { $codes[] = $supplier['code']; }
        foreach (DB::table('suppliers')->orderBy('id')->limit(201)->get(['id', 'code', 'name']) as $supplier) {
            $id = (int) $supplier->id; $code = $supplier->code;
            if ($id < 1 || ! is_string($code) || ! preg_match('/\A[a-z0-9-]{1,100}\z/D', $code)
                || in_array($id, $ids, true) || in_array($code, $codes, true)
                || in_array($code, ['citrus', 'elitlight', 'christmas-trees-1'], true)) { continue; }
            $name = is_string($supplier->name) && trim($supplier->name) !== '' ? $supplier->name : $code;
            $directory['suppliers'][] = ['id' => $id, 'code' => $code, 'name' => $name, 'excluded' => false,
                '_registered_owner_route' => true, 'routes' => [[
                    'key' => 'owner.general', 'brands' => [], 'category_ids' => [], 'category_names' => [],
                    'exclude_category_names' => [], 'exclude_brands' => [], 'all_brands' => true,
                    'whole_brand_routing' => true, 'scope_label_ka' => $name.' — საგარანტიო მომსახურება',
                    'service_name' => null, 'service_phone' => null, 'service_address' => null, 'service_email' => null,
                    'conditions' => null, 'routing_confirmed' => true, 'contact_confirmed' => false,
                    'conditions_confirmed' => false, 'status' => 'owner_profile_required', 'evidence' => [],
                    'official_terms' => null,
                ]]];
            $ids[] = $id; $codes[] = $code;
        }
        return $directory;
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
        $fallbacks = [];
        $custom = [];
        $alta = (int) $supplier['id'] === 2 && $supplier['code'] === 'alta';
        foreach ($supplier['routes'] as $route) {
            if (($route['_custom'] ?? false) === true) {
                if (! ($route['routing_confirmed'] ?? false)) { continue; }
                $scope = $route['_custom_scope'] ?? [];
                if (($scope['product_id'] ?? null) !== null) {
                    if ((int) $scope['product_id'] === (int) $item->product_id && $item->product) {
                        $custom[] = ['route' => $route, 'priority' => 3, 'depth' => 0];
                    }
                } elseif ((int) ($scope['brand_id'] ?? 0) === (int) ($item->product?->brand_id ?? 0)
                    && (int) ($scope['brand_id'] ?? 0) > 0) {
                    if (($scope['category_id'] ?? null) === null) {
                        $custom[] = ['route' => $route, 'priority' => 1, 'depth' => 0];
                    } elseif (($depth = array_search((int) $scope['category_id'], $categoryIds, true)) !== false) {
                        $custom[] = ['route' => $route, 'priority' => 2, 'depth' => $depth];
                    }
                }
                continue;
            }
            if ($alta && ($route['_only_with_owner_profile'] ?? false)
                && empty($route['_profile_changed_fields'])) {
                continue;
            }
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
            $leafCategories = array_map($this->name(...), $route['leaf_category_names'] ?? []);
            if ($leafCategories !== [] && ! in_array($this->name($item->product?->category?->name ?? ''), $leafCategories, true)) {
                // A phone case beneath a phone ancestor is not a phone warranty.
                continue;
            }
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
            if ($alta && ($route['_dealer_fallback'] ?? false)) {
                $fallbacks[] = $route;
                continue;
            }
            $matches[] = $route;
        }
        if ($custom !== []) {
            usort($custom, fn ($a, $b) => ($b['priority'] <=> $a['priority']) ?: ($a['depth'] <=> $b['depth']));
            $best = $custom[0];
            if (isset($custom[1]) && $custom[1]['priority'] === $best['priority'] && $custom[1]['depth'] === $best['depth']) { return null; }
            // A later catalog/category edit must not silently replace a targeted reviewed service.
            if (! $alta && $best['priority'] < 3 && collect($matches)->contains(fn ($route) => ! ($route['all_brands'] ?? false))) { return null; }
            $best['route']['_resolved_category_names'] = $categories;
            return $best['route'];
        }
        $matches = $matches ?: $fallbacks;
        // No cheapest-offer fallback, guessed category, fuzzy brand, or ambiguous route.
        if (count($matches) !== 1) { return null; }
        $resolved = $matches[0];
        // Only catalog categories from this actual delivery item may select a category-specific clause.
        $resolved['_resolved_category_names'] = $categories;
        return $resolved;
    }

    /** Private provenance for supplier-supplied dealer material. */
    public function dealerTermsSource(?array $route): ?array
    {
        $source = $route['_dealer_terms_source'] ?? null;
        if (! is_array($source) || ! is_string($source['title'] ?? null) || ! is_string($source['reference'] ?? null)
            || ! is_string($source['dated_on'] ?? null) || ! is_string($source['sha256'] ?? null)
            || ! preg_match('/\A[0-9a-f]{64}\z/D', $source['sha256'])) {
            return null;
        }

        return array_intersect_key($source, array_flip(['title', 'reference', 'dated_on', 'sha256']));
    }

    /** Source-backed preparation text. This never approves a policy or changes an issued snapshot. */
    public function officialTerms(?array $route): ?array
    {
        $terms = $route['official_terms'] ?? null;
        $dealerSource = $this->dealerTermsSource($route);
        $ownerEntered = ($route['_profile_owner_entered'] ?? false) === true && ($terms['owner_entered'] ?? false) === true;
        if (! is_array($terms) || (! $ownerEntered && ($terms['confirmed'] ?? false) !== true)
            || ! in_array($terms['status'] ?? null, ['substantive', 'partial'], true)) {
            return null;
        }
        $out = ['status' => $terms['status'], 'owner_entered' => $ownerEntered,
            'source_kind' => $ownerEntered ? 'owner_profile' : ($dealerSource ? 'dealer_annex' : 'official')];
        if ($dealerSource !== null) {
            $out['issuer_kind'] = 'seller';
        }
        foreach (['supplier_name', 'scope_notice'] as $key) {
            $out[$key] = is_string($terms[$key] ?? null) ? mb_substr(trim($terms[$key]), 0, 3000) : null;
        }
        $hasText = false;
        foreach (['coverage', 'exclusions', 'customer_duties', 'claims', 'component_terms', 'missing_fields'] as $key) {
            $out[$key] = [];
            foreach (is_array($terms[$key] ?? null) ? array_slice($terms[$key], 0, $ownerEntered ? 10000 : 30) : [] as $text) {
                if (is_string($text) && trim($text) !== '') {
                    $out[$key][] = $ownerEntered ? $text : mb_substr(trim($text), 0, 5000);
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
        $changedFields = $route['_profile_changed_fields'] ?? [];
        if ($dealerSource !== null && array_intersect($changedFields, ['service_name', 'service_phone', 'service_address', 'service_email'])
            && ! in_array('claim_instructions', $changedFields, true)) {
            // Keep the general instruction. Branch details belong to the original service only.
            $out['claims'] = array_slice($out['claims'], 0, 1);
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

        return $ownerEntered || ($hasText && ($out['sources'] !== [] || $dealerSource !== null)) ? $out : null;
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
