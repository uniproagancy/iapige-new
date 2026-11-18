<?php

namespace App\Services\Warranties;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Owner-defined service scope only. Catalog, orders and issued documents are never changed. */
class WarrantyCustomServiceRoutes
{
    private const PREFIX = 'warranty.r.';

    private const MAX_ROWS = 200;

    private const MUTEX_KEY = 'warranty.registry.lock';

    private const MUTEX_VALUE = 'iapi.warranty.registry-lock.v1';

    public function __construct(private WarrantyEventRecorder $events) {}

    /** Contact/terms use the same validated profile editor as an existing service group. */
    public function create(int $supplierId, string $supplierCode, array $scope, array $fields, User $actor): array
    {
        $this->owner($actor);
        $scope = $this->scope($scope);
        $fields = app(WarrantySupplierProfiles::class)->validateRouteFields($fields);
        $this->settingsAvailable();
        try {
            return DB::transaction(function () use ($supplierId, $supplierCode, $scope, $fields, $actor): array {
                $owner = $this->owner($actor, true);
                $this->lockRegistry($owner);
                $supplier = $this->supplier($supplierId, $supplierCode);
                $directory = app(WarrantyServiceDirectory::class)->base();
                $provider = $this->provider($directory, $supplierId, $supplierCode);
                $this->catalog($scope, true);
                if (count($this->rows()) >= self::MAX_ROWS) {
                    $this->invalid('ახალი მიმართულებების ლიმიტი შევსებულია.');
                }
                $this->uniqueScope($provider['routes'], $scope);
                $routeKey = 'owner.'.(string) Str::ulid();
                $state = ['schema' => 'iapi.warranty.custom-route.v1', 'supplier_id' => $supplierId,
                    'supplier_code' => $supplierCode, 'route_key' => $routeKey, 'scope' => $scope,
                    'active' => true, 'revision' => 1, 'created_by' => (int) $owner->id, 'created_at' => now()->toIso8601String(),
                    'updated_by' => (int) $owner->id, 'updated_at' => now()->toIso8601String()];
                $raw = $this->encode($state);
                DB::table('settings')->insert(['key' => $this->key($supplierId, $supplierCode, $routeKey),
                    'value' => $raw, 'created_at' => now(), 'updated_at' => now()]);
                $profiles = app(WarrantySupplierProfiles::class);
                $editor = $profiles->editor($supplierId, $supplierCode, $routeKey, $owner);
                $saved = $profiles->save($supplierId, $supplierCode, $routeKey, $fields, $editor['fingerprint'], $owner);
                $this->events->record('supplier_warranty_route_created', $owner, metadata: [
                    'scope' => 'supplier:'.$supplier->id.'/'.$supplierCode.'/route:'.$routeKey,
                    'reference' => json_encode(['scope' => $scope, 'after' => hash('sha256', $raw)], JSON_THROW_ON_ERROR)]);

                return $saved + ['route_key' => $routeKey];
            }, 3);
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->invalid('მონაცემები სხვა ფანჯარაში შეიცვალა. თავიდან გახსენით.');
            }
            throw $error;
        }
    }

    /** Scope is immutable; pause/re-enable uses a distinct optimistic state fingerprint. */
    public function setActive(int $supplierId, string $supplierCode, string $routeKey, bool $active,
        string $expectedFingerprint, User $actor): array
    {
        $this->owner($actor);
        if (! $this->validKey($routeKey) || ! preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)) {
            $this->invalid('მიმართულება თავიდან გახსენით.');
        }
        $this->settingsAvailable();

        return DB::transaction(function () use ($supplierId, $supplierCode, $routeKey, $active, $expectedFingerprint, $actor): array {
            $owner = $this->owner($actor, true);
            $this->lockRegistry($owner);
            $this->supplier($supplierId, $supplierCode);
            $provider = $this->provider(app(WarrantyServiceDirectory::class)->base(), $supplierId, $supplierCode);
            $key = $this->key($supplierId, $supplierCode, $routeKey);
            $row = DB::table('settings')->where('key', $key)->lockForUpdate()
                ->first(['key', DB::raw('SUBSTR(value, 1, 6001) as value')]);
            if (! $row || ! is_string($row->value)) {
                $this->invalid('მიმართულება ვერ მოიძებნა.');
            }
            $state = $this->decode($row->key, $row->value);
            if (! hash_equals($expectedFingerprint, hash('sha256', $row->value))) {
                $this->invalid('მიმართულება სხვა ფანჯარაში შეიცვალა. თავიდან გახსენით.');
            }
            if ($active) {
                $this->catalog($state['scope'], true);
                $this->uniqueScope($provider['routes'], $state['scope'], $routeKey);
            }
            if ($state['active'] !== $active) {
                if ($state['revision'] >= 2147483646) {
                    $this->invalid('მიმართულების ვერსია გადასამოწმებელია.');
                }
                $state['active'] = $active;
                $state['revision']++;
                $state['updated_by'] = (int) $owner->id;
                $state['updated_at'] = now()->toIso8601String();
                $raw = $this->encode($state);
                DB::table('settings')->where('key', $key)->update(['value' => $raw, 'updated_at' => now()]);
                $this->events->record('supplier_warranty_route_state_changed', $owner, metadata: [
                    'scope' => 'supplier:'.$supplierId.'/'.$supplierCode.'/route:'.$routeKey,
                    'reference' => json_encode(['active' => $active, 'before' => $expectedFingerprint,
                        'after' => hash('sha256', $raw)], JSON_THROW_ON_ERROR)]);
            }

            return app(WarrantySupplierProfiles::class)->editor($supplierId, $supplierCode, $routeKey, $owner)
                + ['route_key' => $routeKey];
        }, 3);
    }

    /** Read only the bounded route namespace; no settings, supplier or catalog writes on view. */
    public function append(array $directory): array
    {
        foreach ($this->rows() as [$state, $raw]) {
            foreach ($directory['suppliers'] as &$supplier) {
                if (($supplier['id'] ?? null) !== $state['supplier_id'] || ($supplier['code'] ?? null) !== $state['supplier_code']
                    || ($supplier['excluded'] ?? true)) {
                    continue;
                }
                $supplier['routes'][] = $this->route($state, $raw);
            }
            unset($supplier);
        }

        return $directory;
    }

    /** Shared namespace capacity lock; called only inside an explicit authorized owner transaction. */
    public function lockRegistry(User $actor): void
    {
        if (DB::transactionLevel() < 1) {
            $this->invalid('შენახვა თავიდან გაიმეორეთ.');
        }
        $this->owner($actor, true);
        $this->settingsAvailable();
        DB::table('settings')->insertOrIgnore(['key' => self::MUTEX_KEY, 'value' => self::MUTEX_VALUE,
            'created_at' => now(), 'updated_at' => now()]);
        $lock = DB::table('settings')->where('key', self::MUTEX_KEY)->lockForUpdate()
            ->first([DB::raw('SUBSTR(value, 1, 129) as value')]);
        if (! $lock || $lock->value !== self::MUTEX_VALUE) {
            $this->invalid('შენახვის საკეტი გადასამოწმებელია.');
        }
    }

    private function rows(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }
        $rows = DB::table('settings')->where('key', 'like', self::PREFIX.'%')->orderBy('key')->limit(self::MAX_ROWS + 1)
            ->get(['key', DB::raw('SUBSTR(value, 1, 6001) as value')]);
        if ($rows->count() > self::MAX_ROWS) {
            $this->invalid('შენახული მიმართულებების რაოდენობა გადასამოწმებელია.');
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_string($row->key) || ! is_string($row->value)) {
                $this->invalid('შენახული მიმართულება დაზიანებულია.');
            }
            $out[] = [$this->decode($row->key, $row->value), $row->value];
        }

        return $out;
    }

    private function route(array $state, string $raw): array
    {
        [$brand, $category, $product] = $this->catalog($state['scope'], false);
        $known = $state['scope']['product_id'] !== null ? $product !== null
            : $brand !== null && ($state['scope']['category_id'] === null || $category !== null);
        $label = $state['scope']['product_id'] !== null
            ? 'პროდუქტი: '.($product?->name ?: '#'.$state['scope']['product_id'])
            : 'ბრენდი: '.($brand?->name ?: '#'.$state['scope']['brand_id'])
                .($state['scope']['category_id'] !== null ? ' · '.($category?->name ?: '#'.$state['scope']['category_id']) : '');

        return ['key' => $state['route_key'], 'brands' => $brand ? [$brand->name] : [],
            'category_ids' => [], 'category_names' => [], 'exclude_category_names' => [], 'exclude_brands' => [],
            'all_brands' => false, 'whole_brand_routing' => false, 'scope_label_ka' => $label,
            'service_name' => null, 'service_phone' => null, 'service_address' => null, 'service_email' => null,
            'conditions' => null, 'routing_confirmed' => $state['active'] && $known,
            'contact_confirmed' => false, 'conditions_confirmed' => false, 'status' => 'owner_profile_required',
            'evidence' => [], 'official_terms' => null, '_custom' => true, '_custom_active' => $state['active'],
            '_custom_scope' => $state['scope'], '_custom_fingerprint' => hash('sha256', $raw),
            '_profile_fingerprint' => hash('sha256', $raw), '_profile_owner_entered' => true,
            '_profile_updated_by' => $state['updated_by'], '_profile_updated_at' => $state['updated_at']];
    }

    private function scope(array $input): array
    {
        if (array_diff(array_keys($input), ['brand_id', 'category_id', 'product_id']) !== []) {
            $this->invalid('მიმართულება არასწორია.');
        }
        $scope = ['brand_id' => null, 'category_id' => null, 'product_id' => null];
        foreach ($scope as $field => $_) {
            $value = $input[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < 1)) {
                $this->invalid('აირჩიეთ არსებული ბრენდი, კატეგორია ან პროდუქტი.');
            }
            $scope[$field] = $value;
        }
        if (($scope['product_id'] !== null && ($scope['brand_id'] !== null || $scope['category_id'] !== null))
            || ($scope['product_id'] === null && $scope['brand_id'] === null)) {
            $this->invalid('აირჩიეთ ბრენდი (სურვილისამებრ კატეგორიით), ან ერთი კონკრეტული პროდუქტი.');
        }

        return $scope;
    }

    private function catalog(array $scope, bool $required): array
    {
        $brand = $scope['brand_id'] !== null ? Brand::find($scope['brand_id']) : null;
        $category = $scope['category_id'] !== null ? Category::find($scope['category_id']) : null;
        $product = $scope['product_id'] !== null ? Product::find($scope['product_id']) : null;
        if ($required && (($scope['brand_id'] !== null && ! $brand) || ($scope['category_id'] !== null && ! $category)
            || ($scope['product_id'] !== null && ! $product))) {
            $this->invalid('არჩეული ბრენდი, კატეგორია ან პროდუქტი აღარ არსებობს.');
        }

        return [$brand, $category, $product];
    }

    private function uniqueScope(array $routes, array $scope, ?string $except = null): void
    {
        [$brand] = $this->catalog($scope, true);
        foreach ($routes as $route) {
            if (($route['key'] ?? null) === $except) {
                continue;
            }
            if (($route['_custom'] ?? false) && ($route['_custom_scope'] ?? null) === $scope) {
                $this->invalid('ეს მიმართულება უკვე არსებობს. გახსენით მისი რედაქტირება ან ჩართეთ არსებული.');
            }
            // Exact product exceptions are intentional. A targeted reviewed brand is edited in its existing group.
            if ($scope['product_id'] === null && ! ($route['_custom'] ?? false) && ($route['routing_confirmed'] ?? false)
                && ! ($route['all_brands'] ?? false)
                && in_array($this->name($brand?->name), array_map($this->name(...), $route['brands'] ?? []), true)) {
                $this->invalid('ამ ბრენდს უკვე აქვს მომსახურების მიმართულება. გახსენით არსებული მიმართულების რედაქტირება; ცალკე გამონაკლისისთვის აირჩიეთ კონკრეტული პროდუქტი.');
            }
        }
    }

    private function provider(array $directory, int $supplierId, string $supplierCode): array
    {
        $matches = array_values(array_filter($directory['suppliers'], fn ($row) => ($row['id'] ?? null) === $supplierId
            && ($row['code'] ?? null) === $supplierCode && ($row['excluded'] ?? true) === false));
        if (count($matches) !== 1) {
            $this->invalid('მომწოდებელი ვერ მოიძებნა. თავიდან გახსენით.');
        }

        return $matches[0];
    }

    private function supplier(int $id, string $code): Supplier
    {
        $supplier = Supplier::query()->whereKey($id)->lockForUpdate()->first();
        if (! $supplier || $supplier->code !== $code) {
            $this->invalid('მომწოდებლის მონაცემები შეიცვალა. თავიდან გახსენით.');
        }

        return $supplier;
    }

    private function decode(string $key, string $raw): array
    {
        if (strlen($raw) > 6000 || ! preg_match('/\Awarranty\.r\.[a-f0-9]{64}\z/D', $key)) {
            $this->invalid('შენახული მიმართულება დაზიანებულია.');
        }
        try {
            $state = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid('შენახული მიმართულება ვერ იკითხება.');
        }
        if (! is_array($state) || array_diff(array_keys($state), ['schema', 'supplier_id', 'supplier_code', 'route_key', 'scope',
            'active', 'revision', 'created_by', 'created_at', 'updated_by', 'updated_at']) !== []
            || ($state['schema'] ?? null) !== 'iapi.warranty.custom-route.v1'
            || ! is_int($state['supplier_id'] ?? null) || $state['supplier_id'] < 1
            || ! is_string($state['supplier_code'] ?? null) || ! preg_match('/\A[a-z0-9._-]{1,100}\z/D', $state['supplier_code'])
            || ! is_string($state['route_key'] ?? null) || ! $this->validKey($state['route_key'])
            || ! is_array($state['scope'] ?? null) || ! is_bool($state['active'] ?? null)
            || ! is_int($state['revision'] ?? null) || $state['revision'] < 1 || $state['revision'] > 2147483646) {
            $this->invalid('შენახული მიმართულება არასწორია.');
        }
        if ($state['scope'] !== $this->scope($state['scope']) || ! hash_equals($key, $this->key($state['supplier_id'], $state['supplier_code'], $state['route_key']))) {
            $this->invalid('მიმართულება მომწოდებელს არ შეესაბამება.');
        }
        foreach (['created_by', 'updated_by'] as $field) {
            if (! is_int($state[$field] ?? null) || $state[$field] < 1) {
                $this->invalid('მიმართულების ავტორი არასწორია.');
            }
        }
        foreach (['created_at', 'updated_at'] as $field) {
            if (! is_string($state[$field] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $state[$field])) {
                $this->invalid('მიმართულების თარიღი არასწორია.');
            }
        }

        return $state;
    }

    private function encode(array $state): string
    {
        $raw = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($raw) > 6000) {
            $this->invalid('მიმართულების მონაცემები ზედმეტად დიდია.');
        }

        return $raw;
    }

    private function key(int $id, string $code, string $route): string
    {
        return self::PREFIX.hash('sha256', json_encode([$id, $code, $route], JSON_THROW_ON_ERROR));
    }

    private function validKey(string $key): bool
    {
        return (bool) preg_match('/\Aowner\.[0-9A-HJKMNP-TV-Z]{26}\z/D', $key);
    }

    private function name(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name ?? '')));
    }

    private function owner(User $actor, bool $lock = false): User
    {
        $query = User::query()->whereKey($actor->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $actor->exists ? $query->first() : null;
        if (! $current?->isAdmin()) {
            throw ValidationException::withMessages(['authorization' => 'შეცვლა მხოლოდ ადმინისტრატორს შეუძლია.']);
        }

        return $current;
    }

    private function settingsAvailable(): void
    {
        if (! Schema::hasTable('settings')) {
            $this->invalid('მიმართულებების შენახვა ჯერ ხელმისაწვდომი არ არის.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['profile' => $message]);
    }
}
