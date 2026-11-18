<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Warranties\WarrantyCustomServiceRoutes;
use App\Services\Warranties\WarrantyServiceDirectory;
use App\Services\Warranties\WarrantySupplierProfiles;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Services extends Component
{
    use AuthorizesWarranties;

    #[Url(as: 'supplier', except: '')]
    public string $supplierCode = '';

    #[Locked]
    public ?int $editingSupplierId = null;

    #[Locked]
    public string $editingSupplierCode = '';

    #[Locked]
    public string $editingRouteKey = '';

    #[Locked]
    public string $profileFingerprint = '';

    public array $form = [];

    public string $notice = '';

    #[Locked]
    public ?int $customSupplierId = null;

    #[Locked]
    public string $customSupplierCode = '';

    #[Locked]
    public array $customRouteFingerprints = [];

    public bool $customOpen = false;

    public string $scopeMode = 'brand';

    public string $customBrandId = '';

    public string $customCategoryId = '';

    public string $customProductId = '';

    public string $productQuery = '';

    public array $customFields = [];

    public function mount(): void
    {
        $this->warrantyActor();
        $this->normalizeSupplierSelection();
    }

    public function selectSupplier(string $code): void
    {
        $this->warrantyActor();
        $this->supplierCode = $code;
        $this->cancelEdit();
        $this->resetCustomForm();
        $this->normalizeSupplierSelection();
    }

    public function updatedSupplierCode(): void
    {
        $this->warrantyActor();
        $this->cancelEdit();
        $this->resetCustomForm();
        $this->normalizeSupplierSelection();
    }

    public function edit(string $routeKey): void
    {
        $actor = $this->warrantyActor(true);
        $this->resetCustomForm();
        $supplier = collect(app(WarrantyServiceDirectory::class)->base()['suppliers'])
            ->first(fn ($row) => $row['code'] === $this->supplierCode && ! $row['excluded']);
        abort_unless($supplier && is_int($supplier['id']), 404);
        $editor = app(WarrantySupplierProfiles::class)->editor($supplier['id'], $supplier['code'], $routeKey, $actor);
        $this->editingSupplierId = $supplier['id'];
        $this->editingSupplierCode = $supplier['code'];
        $this->editingRouteKey = $routeKey;
        $this->profileFingerprint = $editor['fingerprint'];
        $this->form = $editor['fields'];
        $this->notice = '';
        $this->resetValidation();
    }

    public function save(): void
    {
        $actor = $this->warrantyActor(true);
        abort_unless($this->editingSupplierId && $this->editingSupplierCode !== '' && $this->editingRouteKey !== '', 404);
        $saved = app(WarrantySupplierProfiles::class)->save($this->editingSupplierId, $this->editingSupplierCode,
            $this->editingRouteKey, $this->form, $this->profileFingerprint, $actor);
        $this->form = $saved['fields'];
        $this->profileFingerprint = $saved['fingerprint'];
        $this->notice = 'შენახულია — შემდეგ ტალონებს ეს მონაცემები ავტომატურად გაჰყვება.';
    }

    public function cancelEdit(): void
    {
        $this->editingSupplierId = null;
        $this->editingSupplierCode = '';
        $this->editingRouteKey = '';
        $this->profileFingerprint = '';
        $this->form = [];
        $this->notice = '';
        $this->resetValidation();
    }

    public function startCustomRoute(): void
    {
        $this->warrantyActor(true);
        $supplier = $this->selectedActualSupplier();
        $this->cancelEdit();
        $this->resetCustomForm();
        $this->customSupplierId = $supplier['id'];
        $this->customSupplierCode = $supplier['code'];
        $this->customOpen = true;
        $this->customFields = array_fill_keys(['service_name', 'service_phone', 'service_address', 'service_email',
            'coverage', 'conditions', 'claim_instructions', 'source_text'], '');
    }

    public function cancelCustomRoute(): void
    {
        $this->warrantyActor(true);
        $this->resetCustomForm();
        $this->resetValidation();
    }

    public function chooseCustomProduct(int $productId): void
    {
        $this->warrantyActor(true);
        abort_unless($this->customOpen && $this->customSupplierId && $this->scopeMode === 'product'
            && $this->supplierCode === $this->customSupplierCode, 404);
        $product = Product::query()->findOrFail($productId, ['id']);
        $this->customProductId = (string) $product->id;
        $this->productQuery = '';
        $this->resetValidation('customProductId');
    }

    public function createCustomRoute(): void
    {
        $actor = $this->warrantyActor(true);
        $supplier = $this->selectedActualSupplier();
        abort_unless($this->customOpen && $this->customSupplierId === $supplier['id']
            && $this->customSupplierCode === $supplier['code'], 404);
        $rules = ['scopeMode' => ['required', 'in:brand,product']];
        if ($this->scopeMode === 'product') {
            $rules['customProductId'] = ['required', 'integer', 'min:1'];
        } else {
            $rules['customBrandId'] = ['required', 'integer', 'min:1'];
            $rules['customCategoryId'] = ['nullable', 'integer', 'min:1'];
        }
        $valid = $this->validate($rules, [], ['scopeMode' => 'მოქმედების ფარგლები', 'customProductId' => 'პროდუქტი',
            'customBrandId' => 'ბრენდი', 'customCategoryId' => 'კატეგორია']);
        $scope = $this->scopeMode === 'product' ? ['product_id' => (int) $valid['customProductId']]
            : ['brand_id' => (int) $valid['customBrandId'], 'category_id' => filled($valid['customCategoryId'] ?? null) ? (int) $valid['customCategoryId'] : null];
        $created = app(WarrantyCustomServiceRoutes::class)->create($supplier['id'], $supplier['code'], $scope, $this->customFields, $actor);
        $this->edit($created['route_key']);
        $this->notice = 'დამატებულია — ამ მომწოდებლის შესაბამის პროდუქტებს ეს საგარანტიო მონაცემები ავტომატურად გაჰყვება.';
    }

    public function setCustomRouteActive(string $routeKey, bool $active): void
    {
        $actor = $this->warrantyActor(true);
        $supplier = $this->selectedActualSupplier();
        $route = collect($supplier['routes'])->first(fn ($row) => $row['key'] === $routeKey && ($row['_custom'] ?? false));
        abort_unless($route, 404);
        $fingerprint = $this->customRouteFingerprints[$routeKey] ?? '';
        if (! is_string($fingerprint) || ! preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint)) {
            throw ValidationException::withMessages(['route' => 'განაახლეთ გვერდი და შემდეგ შეცვალეთ მდგომარეობა.']);
        }
        $saved = app(WarrantyCustomServiceRoutes::class)->setActive($supplier['id'], $supplier['code'], $routeKey, $active, $fingerprint, $actor);
        if ($this->editingSupplierId === $supplier['id'] && $this->editingRouteKey === $routeKey) {
            $this->form = $saved['fields'];
            $this->profileFingerprint = $saved['fingerprint'];
        }
        $this->notice = $active ? 'საგარანტიო მონაცემები ჩართულია.' : 'გამორთულია — უკვე გაცემული ტალონები უცვლელი რჩება.';
    }

    private function selectedActualSupplier(): array
    {
        $matches = collect(app(WarrantyServiceDirectory::class)->base()['suppliers'])
            ->filter(fn ($supplier) => ($supplier['code'] ?? '') === $this->supplierCode
                && ! ($supplier['excluded'] ?? true) && is_int($supplier['id'] ?? null) && $supplier['id'] > 0)->values();
        abort_unless($matches->count() === 1, 404);

        return $matches->first();
    }

    private function normalizeSupplierSelection(): void
    {
        if ($this->supplierCode === '') {
            return;
        }
        $valid = preg_match('/\A[a-z0-9._-]{1,100}\z/D', $this->supplierCode)
            && collect(app(WarrantyServiceDirectory::class)->base()['suppliers'])->contains(fn ($supplier) => ($supplier['code'] ?? '') === $this->supplierCode && ! ($supplier['excluded'] ?? true)
                && is_int($supplier['id'] ?? null) && $supplier['id'] > 0);
        if (! $valid) {
            $this->supplierCode = '';
            $this->notice = 'მომწოდებელი ვერ მოიძებნა. აირჩიეთ სიიდან.';
        }
    }

    private function resetCustomForm(): void
    {
        $this->customOpen = false;
        $this->customSupplierId = null;
        $this->customSupplierCode = '';
        $this->scopeMode = 'brand';
        $this->customBrandId = '';
        $this->customCategoryId = '';
        $this->customProductId = '';
        $this->productQuery = '';
        $this->customFields = [];
    }

    /** Display-only research metadata. It never approves terms or changes supplier routing. */
    private function supplierReviews(): array
    {
        $unknown = ['checked_on' => null, 'suppliers' => []];
        $path = resource_path('warranty/supplier-review.json');
        if (! is_file($path) || ! is_readable($path)) {
            return $unknown;
        }
        $size = filesize($path);
        if (! is_int($size) || $size < 2 || $size > 256 * 1024) {
            return $unknown;
        }
        try {
            $bytes = file_get_contents($path, false, null, 0, 256 * 1024 + 1);
            if (! is_string($bytes) || strlen($bytes) > 256 * 1024) {
                return $unknown;
            }
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $unknown;
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== 'iapi.warranty.supplier-review.v1'
            || ! is_string($data['checked_on'] ?? null)
            || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $data['checked_on'])
            || ! checkdate((int) substr($data['checked_on'], 5, 2), (int) substr($data['checked_on'], 8, 2), (int) substr($data['checked_on'], 0, 4))
            || ! is_array($data['suppliers'] ?? null) || ! array_is_list($data['suppliers']) || count($data['suppliers']) > 100) {
            return $unknown;
        }
        $indexed = [];
        foreach ($data['suppliers'] as $review) {
            if (! is_array($review) || ! array_key_exists('id', $review)
                || ! ($review['id'] === null || (is_int($review['id']) && $review['id'] > 0))
                || ! is_string($review['code'] ?? null) || ! preg_match('/\A[a-z0-9._-]{1,100}\z/D', $review['code'])
                || ! in_array($review['form_type'] ?? null, ['dealer_original', 'official_terms', 'partial', 'contact_only', 'unlinked', 'excluded'], true)) {
                return $unknown;
            }
            foreach (['status_ka', 'scope_notice_ka'] as $field) {
                if (! is_string($review[$field] ?? null) || trim($review[$field]) === '' || mb_strlen($review[$field]) > 4000) {
                    return $unknown;
                }
            }
            if (! array_key_exists('original_form_scope_ka', $review)
                || ! ($review['original_form_scope_ka'] === null
                    || (is_string($review['original_form_scope_ka']) && mb_strlen($review['original_form_scope_ka']) <= 4000))) {
                return $unknown;
            }
            foreach (['missing_fields', 'conflicts_ka'] as $field) {
                if (! is_array($review[$field] ?? null) || ! array_is_list($review[$field]) || count($review[$field]) > 50) {
                    return $unknown;
                }
                foreach ($review[$field] as $line) {
                    if (! is_string($line) || trim($line) === '' || mb_strlen($line) > 4000) {
                        return $unknown;
                    }
                }
            }
            // JSON keys preserve null-versus-integer identity; a code match alone is insufficient.
            $key = json_encode([$review['id'], $review['code']], JSON_THROW_ON_ERROR);
            if (isset($indexed[$key])) {
                return $unknown;
            }
            $indexed[$key] = $review;
        }

        return ['checked_on' => $data['checked_on'], 'suppliers' => $indexed];
    }

    public function render()
    {
        $actor = $this->warrantyActor();
        $directory = app(WarrantyServiceDirectory::class)->all();
        $reviews = $this->supplierReviews();
        $profiles = app(WarrantySupplierProfiles::class);
        $suppliers = collect(array_merge($directory['suppliers'], $directory['unlinked_suppliers'] ?? []))->where('excluded', false)
            ->map(fn ($supplier) => array_merge($supplier, ['supplier_legal_name' => $profiles->legalName(
                is_int($supplier['id'] ?? null) ? $supplier['id'] : null, $supplier['code'])]));
        $selected = $suppliers->filter(fn ($supplier) => $this->supplierCode === '' || $supplier['code'] === $this->supplierCode)
            ->map(function ($supplier) use ($reviews) {
                $groups = [];
                foreach ($supplier['routes'] as $route) {
                    $route['official_terms'] = app(WarrantyServiceDirectory::class)->officialTerms($route);
                    // Each editor is bound to one exact route, even when two services share a contact.
                    $groups[$route['key']] = $route + ['scopes' => [$route['scope_label_ka']]];
                }
                $reviewKey = json_encode([$supplier['id'] ?? null, $supplier['code']], JSON_THROW_ON_ERROR);

                return array_merge($supplier, ['groups' => $groups, 'review' => $reviews['suppliers'][$reviewKey] ?? null]);
            });
        $this->customRouteFingerprints = [];
        if ($actor->isAdmin()) {
            foreach ($selected as $supplier) {
                foreach ($supplier['groups'] as $route) {
                    if (($route['_custom'] ?? false) && is_string($route['_custom_fingerprint'] ?? null)) {
                        $this->customRouteFingerprints[$route['key']] = $route['_custom_fingerprint'];
                    }
                }
            }
        }
        $lookupOpen = $actor->isAdmin() && $this->customOpen && $this->customSupplierCode === $this->supplierCode;
        $brands = $lookupOpen && $this->scopeMode === 'brand' ? Brand::query()->orderBy('name')->limit(500)->get(['id', 'name']) : collect();
        $categories = $lookupOpen && $this->scopeMode === 'brand' ? Category::query()->with('translations')->orderBy('id')->limit(500)->get(['id', 'parent_id']) : collect();
        $selectedProduct = $lookupOpen && $this->scopeMode === 'product' && ctype_digit($this->customProductId)
            ? Product::query()->with('translations')->find((int) $this->customProductId, ['id', 'sku']) : null;
        $query = trim($this->productQuery);
        $products = $lookupOpen && $this->scopeMode === 'product' && mb_strlen($query) >= 2 && mb_strlen($query) <= 120
            ? Product::query()->with(['translations' => fn ($q) => $q->select('id', 'product_id', 'locale', 'name')])
                ->where(fn ($q) => $q->where('sku', 'like', '%'.$query.'%')
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$query.'%')))
                ->orderBy('id')->limit(15)->get(['id', 'sku']) : collect();

        return view('livewire.admin.warranties.services', ['suppliers' => $suppliers, 'selected' => $selected,
            'isOwner' => $actor->isAdmin(), 'reviewCheckedOn' => $reviews['checked_on'], 'customBrands' => $brands,
            'customCategories' => $categories, 'customProducts' => $products,
            'selectedCustomProduct' => $selectedProduct])->layout('layouts.admin', ['title' => __('warranty.service_directory')]);
    }
}
