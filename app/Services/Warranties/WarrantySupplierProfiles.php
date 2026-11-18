<?php

namespace App\Services\Warranties;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Owner-maintained warranty text for an existing exact supplier route; it never changes routing scope. */
class WarrantySupplierProfiles
{
    private const PREFIX = 'warranty.p.';

    private const LEGAL_PREFIX = 'warranty.legal.';

    private const FIELDS = ['service_name', 'service_phone', 'service_address', 'service_email',
        'coverage', 'conditions', 'claim_instructions', 'source_text'];

    public function __construct(private WarrantyEventRecorder $events) {}

    /** Shared contact/text validation for one atomic owner-created service direction. */
    public function validateRouteFields(array $input): array
    {
        if (array_diff(array_keys($input), self::FIELDS) !== []) {
            $this->invalid('profile', 'მიმართულების ველები არასწორია.');
        }

        return $this->validateFields($input);
    }

    public function editor(int $supplierId, string $supplierCode, string $routeKey, User $actor): array
    {
        $this->staff($actor);
        [$supplier, $base] = $this->route($supplierId, $supplierCode, $routeKey);
        $raw = $this->raw($this->key($supplierId, $supplierCode, $routeKey));
        $profile = $raw === null ? null : $this->decode($raw, $supplierId, $supplierCode, $routeKey);
        $effective = $profile ? $this->apply($base, $profile, $raw) : $base;
        $legalRaw = $this->legalRaw($this->legalKey($supplierId, $supplierCode));
        $legalDefault = $this->seedLegalName($supplierId, $supplierCode);
        $legalName = $legalRaw === null ? $legalDefault : $this->decodeLegal($legalRaw, $supplierId, $supplierCode)['legal_name'];

        return ['supplier' => $supplier, 'route' => $effective,
            'fields' => $this->fields($effective) + ['supplier_legal_name' => $legalName ?? ''],
            'fingerprint' => $this->editorFingerprint($base, $raw, $legalRaw, $legalDefault)];
    }

    /** A supplier's legal company is independent of each brand/category service route. */
    public function legalName(?int $supplierId, string $supplierCode, ?string $default = null): ?string
    {
        if (! $supplierId || $supplierId < 1 || ! preg_match('/\A[a-z0-9._-]{1,100}\z/D', $supplierCode)) {
            return null;
        }
        $fallback = $default === null ? $this->seedLegalName($supplierId, $supplierCode) : $this->legalValue($default);
        $raw = $this->legalRaw($this->legalKey($supplierId, $supplierCode));

        // An explicit owner clear stays empty even when a source default is available.
        return $raw === null ? $fallback : $this->decodeLegal($raw, $supplierId, $supplierCode)['legal_name'];
    }

    public function save(int $supplierId, string $supplierCode, string $routeKey, array $input,
        string $expectedFingerprint, User $actor): array
    {
        $this->staff($actor, true);
        if (array_diff(array_keys($input), [...self::FIELDS, 'supplier_legal_name']) !== []
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)) {
            $this->invalid('profile', 'მონაცემები თავიდან გახსენით.');
        }
        if (! Schema::hasTable('settings')) {
            $this->invalid('profile', 'მომწოდებლის მონაცემების შენახვა ჯერ ხელმისაწვდომი არ არის.');
        }
        try {
            return DB::transaction(function () use ($supplierId, $supplierCode, $routeKey, $input, $expectedFingerprint, $actor): array {
                $owner = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if (! $owner?->isAdmin()) {
                    $this->invalid('authorization', 'შეცვლა მხოლოდ ადმინისტრატორს შეუძლია.');
                }
                app(WarrantyCustomServiceRoutes::class)->lockRegistry($owner);
                [$supplier, $base] = $this->route($supplierId, $supplierCode, $routeKey);
                $actual = Supplier::query()->whereKey($supplierId)->lockForUpdate()->first();
                if (! $actual || $actual->code !== $supplierCode) {
                    $this->invalid('profile', 'მომწოდებლის მონაცემები შეიცვალა. თავიდან გახსენით.');
                }
                $key = $this->key($supplierId, $supplierCode, $routeKey);
                $row = DB::table('settings')->where('key', $key)->lockForUpdate()->first(['key', 'value']);
                $raw = $row?->value;
                if ($raw !== null && ! is_string($raw)) {
                    $this->invalid('profile', 'შენახული მონაცემები ვერ იკითხება.');
                }
                $legalKey = $this->legalKey($supplierId, $supplierCode);
                $legalRow = DB::table('settings')->where('key', $legalKey)->lockForUpdate()
                    ->first(['key', DB::raw('SUBSTR(value, 1, 8193) as value')]);
                $legalRaw = $legalRow?->value;
                if ($legalRow && (! is_string($legalRaw) || strlen($legalRaw) > 8192)) {
                    $this->invalid('profile', 'შენახული იურიდიული დასახელება ვერ იკითხება.');
                }
                $legalDefault = $this->seedLegalName($supplierId, $supplierCode);
                $previousLegal = $legalRaw === null ? $legalDefault : $this->decodeLegal($legalRaw, $supplierId, $supplierCode)['legal_name'];
                if (! hash_equals($expectedFingerprint, $this->editorFingerprint($base, $raw, $legalRaw, $legalDefault))) {
                    $this->invalid('profile', 'მონაცემები სხვა ფანჯარაში შეიცვალა. თავიდან გახსენით და შემდეგ შეინახეთ.');
                }
                $previous = $raw === null ? null : $this->decode($raw, $supplierId, $supplierCode, $routeKey);
                $effective = $previous ? $this->apply($base, $previous, $raw) : $base;
                $legalName = array_key_exists('supplier_legal_name', $input)
                    ? $this->legalValue($input['supplier_legal_name']) : $previousLegal;
                $routeInput = array_intersect_key($input, array_flip(self::FIELDS));
                $values = $this->validateFields(array_replace($this->fields($effective), $routeInput));
                $routeChanged = $values !== $this->fields($effective);
                $baseValues = $this->fields($base);
                $overrides = [];
                foreach (self::FIELDS as $field) {
                    if ($values[$field] !== $baseValues[$field]) {
                        $overrides[$field] = $values[$field];
                    }
                }
                $profile = ['schema' => 'iapi.warranty.supplier-profile.v1', 'supplier_id' => $supplierId,
                    'supplier_code' => $supplierCode, 'route_key' => $routeKey, 'fields' => $overrides,
                    'changed_fields' => array_keys($overrides), 'updated_by' => (int) $owner->id,
                    'updated_at' => now()->toIso8601String()];
                $json = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($json) > 60000) {
                    $this->invalid('profile', 'ტექსტი ზედმეტად დიდია. შეამოკლეთ პირობები.');
                }
                if ($routeChanged) {
                    if (! $row && DB::table('settings')->where('key', 'like', self::PREFIX.'%')->limit(201)->get(['key'])->count() >= 200) {
                        $this->invalid('profile', 'საგარანტიო მიმართულებების ტექსტების ლიმიტი შევსებულია. შეცვალეთ არსებული მიმართულება.');
                    }
                    if ($row) {
                        DB::table('settings')->where('key', $key)->update(['value' => $json, 'updated_at' => now()]);
                    } else {
                        DB::table('settings')->insert(['key' => $key, 'value' => $json, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $legalChanged = $legalName !== $previousLegal;
                if ($legalChanged) {
                    $legalRaw = json_encode(['schema' => 'iapi.warranty.supplier-legal.v1', 'supplier_id' => $supplierId,
                        'supplier_code' => $supplierCode, 'legal_name' => $legalName, 'updated_by' => (int) $owner->id,
                        'updated_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    if ($legalRow) {
                        DB::table('settings')->where('key', $legalKey)->update(['value' => $legalRaw, 'updated_at' => now()]);
                    } else {
                        DB::table('settings')->insert(['key' => $legalKey, 'value' => $legalRaw, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $fingerprint = $this->editorFingerprint($base, $routeChanged ? $json : $raw, $legalRaw, $legalDefault);
                $this->events->record('supplier_warranty_profile_updated', $owner, metadata: [
                    'scope' => 'supplier:'.$supplierId.'/'.$supplierCode.'/route:'.$routeKey,
                    'reference' => json_encode(['before' => $expectedFingerprint, 'after' => $fingerprint,
                        'fields' => [...($routeChanged ? array_keys($overrides) : []), ...($legalChanged ? ['supplier_legal_name'] : [])]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

                return ['supplier' => $supplier, 'route' => $routeChanged ? $this->apply($base, $profile, $json) : $effective,
                    'fields' => $values + ['supplier_legal_name' => $legalName ?? ''], 'fingerprint' => $fingerprint];
            }, 3);
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->invalid('profile', 'მონაცემები სხვა ფანჯარაში შეიცვალა. თავიდან გახსენით.');
            }
            throw $error;
        }
    }

    /** Read only this bounded namespace, never the application's other settings. */
    public function overlay(array $directory): array
    {
        if (! Schema::hasTable('settings')) {
            return $directory;
        }
        $rows = DB::table('settings')->where('key', 'like', self::PREFIX.'%')->limit(201)->get(['key', 'value']);
        if ($rows->count() > 200) {
            $this->invalid('profile', 'მომწოდებლების შენახული მონაცემები გადასამოწმებელია.');
        }
        $indexed = [];
        foreach ($rows as $row) {
            if (! is_string($row->key) || ! preg_match('/\Awarranty\.p\.[a-f0-9]{64}\z/D', $row->key)
                || ! is_string($row->value)) {
                $this->invalid('profile', 'შენახული საგარანტიო მონაცემები დაზიანებულია.');
            }
            $indexed[$row->key] = $row->value;
        }
        foreach ($directory['suppliers'] as &$supplier) {
            if (! is_int($supplier['id'] ?? null) || $supplier['id'] < 1 || ($supplier['excluded'] ?? true)) {
                continue;
            }
            foreach ($supplier['routes'] as &$route) {
                $key = $this->key($supplier['id'], $supplier['code'], $route['key']);
                if (! isset($indexed[$key])) {
                    continue;
                }
                $profile = $this->decode($indexed[$key], $supplier['id'], $supplier['code'], $route['key']);
                $route = $this->apply($route, $profile, $indexed[$key]);
            }
            unset($route);
        }
        unset($supplier);

        return $directory;
    }

    private function route(int $supplierId, string $supplierCode, string $routeKey): array
    {
        $base = app(WarrantyServiceDirectory::class)->base();
        $suppliers = array_values(array_filter($base['suppliers'], fn ($row) => ($row['id'] ?? null) === $supplierId
            && ($row['code'] ?? null) === $supplierCode && ($row['excluded'] ?? true) === false));
        if (count($suppliers) !== 1) {
            $this->invalid('profile', 'მომწოდებლის მონაცემები ვერ მოიძებნა.');
        }
        $routes = array_values(array_filter($suppliers[0]['routes'], fn ($row) => ($row['key'] ?? null) === $routeKey));
        if (count($routes) !== 1) {
            $this->invalid('profile', 'მომსახურების მიმართულება ვერ მოიძებნა.');
        }

        return [$suppliers[0], $routes[0]];
    }

    private function fields(array $route): array
    {
        $terms = $route['official_terms'] ?? [];
        $join = static fn ($lines) => implode("\n", array_filter(is_array($lines) ? $lines : [], 'is_string'));

        return ['service_name' => (string) ($route['service_name'] ?? ''), 'service_phone' => (string) ($route['service_phone'] ?? ''),
            'service_address' => (string) ($route['service_address'] ?? ''), 'service_email' => (string) ($route['service_email'] ?? ''),
            'coverage' => $join($terms['coverage'] ?? []),
            'conditions' => $join(array_merge($terms['exclusions'] ?? [], $terms['customer_duties'] ?? [])) ?: (string) ($route['conditions'] ?? ''),
            'claim_instructions' => $join($terms['claims'] ?? []), 'source_text' => (string) ($route['_profile_source_text'] ?? '')];
    }

    private function apply(array $route, array $profile, string $raw): array
    {
        $base = $route;
        $fields = $profile['fields'];
        foreach (['service_name', 'service_phone', 'service_address', 'service_email'] as $field) {
            if (array_key_exists($field, $fields)) {
                $route[$field] = $fields[$field] === '' ? null : $fields[$field];
            }
        }
        $terms = is_array($route['official_terms'] ?? null) ? $route['official_terms'] : [];
        foreach (['coverage', 'exclusions', 'customer_duties', 'claims', 'component_terms', 'missing_fields', 'sources'] as $field) {
            if (! is_array($terms[$field] ?? null)) {
                $terms[$field] = [];
            }
        }
        $split = static fn (string $value) => $value === '' ? [] : preg_split('/\r\n|\r|\n/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $overridden = [];
        if (array_key_exists('coverage', $fields)) {
            $terms['coverage'] = $split($fields['coverage']);
            $overridden[] = 'coverage';
        }
        if (array_key_exists('conditions', $fields)) {
            $terms['exclusions'] = $split($fields['conditions']);
            $terms['customer_duties'] = [];
            $route['conditions'] = $fields['conditions'] === '' ? null : $fields['conditions'];
            $overridden = array_merge($overridden, ['exclusions', 'customer_duties']);
        }
        if (array_key_exists('claim_instructions', $fields)) {
            $terms['claims'] = $split($fields['claim_instructions']);
            $overridden[] = 'claims';
        }
        if ($overridden !== [] && is_array($terms['conditional_sections'] ?? null)) {
            $terms['conditional_sections'] = array_values(array_filter($terms['conditional_sections'],
                fn ($section) => ! in_array($section['field'] ?? null, $overridden, true)));
        }
        // Owner text is not presented as an independently verified supplier original or fabricated URL.
        $terms['owner_entered'] = true;
        $terms['source_kind'] = 'owner_profile';
        $terms['status'] = ($terms['coverage'] !== [] && ($terms['exclusions'] !== [] || $terms['customer_duties'] !== []) && $terms['claims'] !== [])
            ? 'substantive' : 'partial';
        $terms['confirmed'] = false;
        $route['official_terms'] = $terms;
        $route['_profile_owner_entered'] = true;
        $route['_profile_updated_by'] = $profile['updated_by'];
        $route['_profile_updated_at'] = $profile['updated_at'];
        $route['_profile_changed_fields'] = $profile['changed_fields'];
        $route['_profile_source_text'] = $fields['source_text'] ?? '';
        $route['_profile_fingerprint'] = $this->fingerprint($base, $raw);

        return $route;
    }

    private function validateFields(array $input): array
    {
        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.match ($field) {
                'service_name', 'service_phone', 'service_email' => 255, 'service_address' => 2000,
                'source_text' => 3000, 'claim_instructions' => 5000, default => 10000,
            }];
        }
        $rules['service_email'][] = 'email';
        $valid = Validator::make($input, $rules)->validate();
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($valid[$field] ?? ''));
            if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
                $this->invalid('profile', 'ტექსტში მიუღებელი სიმბოლოებია.');
            }
            $out[$field] = $value;
        }

        return $out;
    }

    private function decode(string $raw, int $supplierId, string $supplierCode, string $routeKey): array
    {
        if (strlen($raw) > 60000) {
            $this->invalid('profile', 'შენახული მონაცემები ზედმეტად დიდია.');
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid('profile', 'შენახული მონაცემები ვერ იკითხება.');
        }
        if (! is_array($data) || array_diff(array_keys($data), ['schema', 'supplier_id', 'supplier_code', 'route_key', 'fields',
            'changed_fields', 'updated_by', 'updated_at']) !== []
            || ($data['schema'] ?? null) !== 'iapi.warranty.supplier-profile.v1' || ($data['supplier_id'] ?? null) !== $supplierId
            || ($data['supplier_code'] ?? null) !== $supplierCode || ($data['route_key'] ?? null) !== $routeKey
            || ! is_array($data['fields'] ?? null) || array_diff(array_keys($data['fields']), self::FIELDS) !== []
            || ($data['changed_fields'] ?? null) !== array_keys($data['fields']) || ! is_int($data['updated_by'] ?? null)
            || $data['updated_by'] < 1 || ! is_string($data['updated_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $data['updated_at'])) {
            $this->invalid('profile', 'შენახული მონაცემები მომწოდებელს არ შეესაბამება.');
        }
        foreach ($data['fields'] as $value) {
            if (! is_string($value)) {
                $this->invalid('profile', 'შენახული ველი არასწორია.');
            }
        }
        $this->validateFields($data['fields']);

        return $data;
    }

    private function raw(string $key): ?string
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }
        $value = DB::table('settings')->where('key', $key)->value('value');
        if ($value !== null && ! is_string($value)) {
            $this->invalid('profile', 'შენახული მონაცემები ვერ იკითხება.');
        }

        return $value;
    }

    private function legalRaw(string $key): ?string
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }
        $row = DB::table('settings')->where('key', $key)->first([DB::raw('SUBSTR(value, 1, 8193) as value')]);
        if (! $row) {
            return null;
        }
        if (! is_string($row->value) || strlen($row->value) > 8192) {
            $this->invalid('profile', 'შენახული იურიდიული დასახელება ვერ იკითხება.');
        }

        return $row->value;
    }

    private function legalValue(mixed $value): ?string
    {
        $valid = Validator::make(['supplier_legal_name' => $value],
            ['supplier_legal_name' => ['nullable', 'string', 'max:255']], [],
            ['supplier_legal_name' => 'მომწოდებლის იურიდიული დასახელება'])->validate();
        $name = trim((string) ($valid['supplier_legal_name'] ?? ''));
        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            $this->invalid('supplier_legal_name', 'იურიდიულ დასახელებაში მიუღებელი სიმბოლოებია.');
        }

        return $name === '' ? null : $name;
    }

    private function decodeLegal(string $raw, int $supplierId, string $supplierCode): array
    {
        if (strlen($raw) > 8192) {
            $this->invalid('profile', 'შენახული იურიდიული დასახელება ზედმეტად დიდია.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid('profile', 'შენახული იურიდიული დასახელება ვერ იკითხება.');
        }
        if (! is_array($data) || array_diff(array_keys($data), ['schema', 'supplier_id', 'supplier_code', 'legal_name', 'updated_by', 'updated_at']) !== []
            || ($data['schema'] ?? null) !== 'iapi.warranty.supplier-legal.v1'
            || ($data['supplier_id'] ?? null) !== $supplierId || ($data['supplier_code'] ?? null) !== $supplierCode
            || ! array_key_exists('legal_name', $data) || ! ($data['legal_name'] === null || is_string($data['legal_name']))
            || ! is_int($data['updated_by'] ?? null) || $data['updated_by'] < 1 || ! is_string($data['updated_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $data['updated_at'])) {
            $this->invalid('profile', 'იურიდიული დასახელება მომწოდებელს არ შეესაბამება.');
        }
        $data['legal_name'] = $this->legalValue($data['legal_name']);

        return $data;
    }

    private function seedLegalName(int $supplierId, string $supplierCode): ?string
    {
        $path = resource_path('warranty/supplier-legal-identities.json');
        if (! is_file($path)) {
            return null;
        }
        $raw = is_readable($path) ? file_get_contents($path, false, null, 0, 65537) : false;
        if (! is_string($raw) || strlen($raw) > 65536) {
            $this->invalid('profile', 'მომწოდებლის იურიდიული მონაცემები ვერ იკითხება.');
        }
        try {
            $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid('profile', 'მომწოდებლის იურიდიული მონაცემები ვერ იკითხება.');
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== 'iapi.warranty.supplier-legal-identities.v1'
            || ! is_array($data['identities'] ?? null) || ! array_is_list($data['identities']) || count($data['identities']) > 100) {
            $this->invalid('profile', 'მომწოდებლის იურიდიული მონაცემები არასწორია.');
        }
        $indexed = [];
        foreach ($data['identities'] as $identity) {
            if (! is_array($identity) || ! is_int($identity['supplier_id'] ?? null) || $identity['supplier_id'] < 1
                || ! is_string($identity['supplier_code'] ?? null) || ! preg_match('/\A[a-z0-9._-]{1,100}\z/D', $identity['supplier_code'])
                || ! is_string($identity['legal_name'] ?? null) || trim($identity['legal_name']) === ''
                || ! is_array($identity['evidence'] ?? null)) {
                $this->invalid('profile', 'მომწოდებლის იურიდიული მონაცემები არასწორია.');
            }
            $name = $this->legalValue($identity['legal_name']);
            $key = $this->legalKey($identity['supplier_id'], $identity['supplier_code']);
            if (array_key_exists($key, $indexed)) {
                $this->invalid('profile', 'მომწოდებლის იურიდიული მონაცემები გამეორებულია.');
            }
            $indexed[$key] = $name;
        }

        return $indexed[$this->legalKey($supplierId, $supplierCode)] ?? null;
    }

    private function legalKey(int $supplierId, string $supplierCode): string
    {
        return self::LEGAL_PREFIX.hash('sha256', json_encode([$supplierId, $supplierCode], JSON_THROW_ON_ERROR));
    }

    private function editorFingerprint(array $base, ?string $raw, ?string $legalRaw, ?string $legalDefault): string
    {
        return hash('sha256', json_encode([$base, $raw, $legalRaw, $legalDefault],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function key(int $supplierId, string $supplierCode, string $routeKey): string
    {
        return self::PREFIX.hash('sha256', json_encode([$supplierId, $supplierCode, $routeKey], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(array $base, ?string $raw): string
    {
        return hash('sha256', json_encode([$base, $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function staff(User $actor, bool $owner = false): void
    {
        $current = $actor->exists ? User::find($actor->id) : null;
        if (! $current || ! ($owner ? $current->isAdmin() : $current->isStaff())) {
            $this->invalid('authorization', $owner ? 'შეცვლა მხოლოდ ადმინისტრატორს შეუძლია.' : 'ნახვა მხოლოდ თანამშრომელს შეუძლია.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
