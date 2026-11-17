<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\WarrantyPolicy;
use App\Services\Warranties\WarrantyDocumentStore;
use App\Services\Warranties\WarrantyPolicyManager;
use App\Services\Warranties\WarrantySupplierIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Policies extends Component
{
    use AuthorizesWarranties;
    use WithFileUploads;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Locked]
    public ?int $policyId = null;

    #[Locked]
    public string $policyStatus = 'draft';

    #[Url(as: 'supplier', except: '')]
    public string $supplierFilter = '';

    #[Url(as: 'q', except: '')]
    public string $policySearch = '';

    #[Url(as: 'product', except: '')]
    public string $productFilter = '';

    public string $productSearch = '';

    public array $form = [];

    public $documentFile;

    public string $documentRole = 'source';

    protected const FIELDS = [
        'supplier_id', 'brand_id', 'category_id', 'product_id', 'name',
        'term_value', 'term_unit', 'term_text', 'component_terms',
        'issuer_name', 'issuer_address', 'issuer_contact', 'territory',
        'coverage', 'conditions', 'claim_instructions', 'start_basis',
        'service_name', 'service_address', 'service_phone', 'service_email',
        'source_url', 'source_reference', 'source_confirmed', 'requires_original',
        'original_not_required_reason', 'valid_from', 'valid_until', 'review_notes', 'customer_reference',
    ];

    public function mount(): void
    {
        $this->warrantyActor(true);
        $this->newPolicy();
        $query = request()->query('q');
        $this->policySearch = is_string($query) ? mb_substr(trim($query), 0, 120) : '';
        $supplierId = filter_var(request()->query('supplier'), FILTER_VALIDATE_INT);
        $productId = filter_var(request()->query('product'), FILTER_VALIDATE_INT);
        if ($supplierId !== false && $supplierId > 0) {
            $this->supplierFilter = (string) $supplierId;
        }
        if ($productId !== false && $productId > 0) {
            $this->productFilter = (string) $productId;
        }
        $id = filter_var(request()->query('policy'), FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $this->editPolicy($id);
        } elseif ($productId !== false && $productId > 0 && $supplierId !== false && $supplierId > 0) {
            $this->openExactProductDraft($productId, $supplierId);
        }
    }

    protected function openExactProductDraft(int $productId, int $supplierId): void
    {
        $product = Product::query()->findOrFail($productId);
        $supplier = Supplier::query()->findOrFail($supplierId);
        if (in_array(strtolower(trim($supplier->code)), ['citrus', 'elitlight', 'christmas-trees-1'], true)
            && ! WarrantySupplierIdentity::isSkytech((int) $supplier->id, $supplier->code)) {
            $this->addError('product', __('warranty.query_supplier_excluded'));
            return;
        }
        if (! ProductOffer::query()->where('product_id', $product->id)->where('supplier_id', $supplier->id)->exists()) {
            $this->addError('product', __('warranty.query_offer_missing'));
            return;
        }
        $draft = WarrantyPolicy::query()->where('product_id', $product->id)->where('supplier_id', $supplier->id)
            ->where('status', WarrantyPolicy::DRAFT)->orderByDesc('id')->first();
        if ($draft) {
            $this->editPolicy($draft->id);
            return;
        }
        // Opening a link never creates or approves a record, or copies another supplier's terms.
        $this->form['product_id'] = $product->id;
        $this->form['supplier_id'] = $supplier->id;
    }

    public function newPolicy(): void
    {
        $this->warrantyActor(true);
        $this->policyId = null;
        $this->policyStatus = 'draft';
        $this->form = array_fill_keys(self::FIELDS, '');
        $this->form['term_unit'] = 'months';
        $this->form['start_basis'] = '';
        $this->form['requires_original'] = true;
        $this->form['source_confirmed'] = false;
        $this->documentFile = null;
        $this->documentRole = 'source';
        $this->productSearch = '';
        $this->resetValidation();
    }

    public function editPolicy(int $id): void
    {
        $this->warrantyActor(true);
        $policy = WarrantyPolicy::query()->findOrFail($id);
        $this->policyId = $policy->id;
        $this->policyStatus = $policy->status;
        foreach (self::FIELDS as $field) {
            $value = $policy->getAttribute($field);
            $this->form[$field] = in_array($field, ['valid_from', 'valid_until'], true)
                ? $value?->format('Y-m-d') ?? '' : ($value ?? '');
        }
        $this->documentFile = null;
        $this->documentRole = 'source';
        $this->productSearch = '';
        $this->resetValidation();
    }

    public function selectProduct(int $id): void
    {
        $this->warrantyActor(true);
        Product::query()->findOrFail($id);
        $this->form['product_id'] = $id;
        $this->productSearch = '';
    }

    public function clearProduct(): void
    {
        $this->warrantyActor(true);
        $this->form['product_id'] = '';
        $this->productSearch = '';
    }

    public function updatedSupplierFilter(): void
    {
        $this->warrantyActor(true);
        $this->resetPage();
    }

    public function updatedPolicySearch(): void
    {
        $this->warrantyActor(true);
        $this->resetPage();
    }

    public function clearProductFilter(): void
    {
        $this->warrantyActor(true);
        $this->productFilter = '';
        $this->resetPage();
    }

    public function saveDraft(): void
    {
        $actor = $this->warrantyActor(true);
        $this->resetValidation();
        $data = array_intersect_key($this->form, array_flip(self::FIELDS));
        foreach (['supplier_id', 'brand_id', 'category_id', 'product_id', 'term_value'] as $field) {
            $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        }
        foreach (['valid_from', 'valid_until'] as $field) {
            $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        }
        $policy = $this->policyId === null ? null : WarrantyPolicy::query()->findOrFail($this->policyId);
        $saved = app(WarrantyPolicyManager::class)->saveDraft($policy, $data, $actor);
        $this->editPolicy($saved->id);
        $this->dispatch('toast', message: __('warranty.draft_saved'));
    }

    public function approve(): void
    {
        $actor = $this->warrantyActor(true);
        abort_if($this->policyId === null, 404);
        // Approval must apply to saved content; unsaved form edits cannot change its scope.
        $this->assertSavedForm();
        $policy = app(WarrantyPolicyManager::class)->approve(WarrantyPolicy::query()->findOrFail($this->policyId), $actor);
        $this->editPolicy($policy->id);
        $this->dispatch('toast', message: __('warranty.policy_approved'));
    }

    public function archive(): void
    {
        $actor = $this->warrantyActor(true);
        abort_if($this->policyId === null, 404);
        $policy = app(WarrantyPolicyManager::class)->archive(WarrantyPolicy::query()->findOrFail($this->policyId), $actor);
        $this->editPolicy($policy->id);
        $this->dispatch('toast', message: __('warranty.policy_archived'));
    }

    public function uploadDocument(): void
    {
        $actor = $this->warrantyActor(true);
        $this->validate([
            'policyId' => ['required', 'integer'],
            'documentFile' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
            'documentRole' => ['required', Rule::in(['source', 'official_form', 'terms'])],
        ], [], ['documentFile' => __('warranty.document'), 'policyId' => __('warranty.save_policy_first')]);
        $policy = WarrantyPolicy::query()->findOrFail($this->policyId);
        app(WarrantyDocumentStore::class)->forPolicy($policy, $this->documentFile, $this->documentRole, $actor);
        $this->documentFile = null;
        $this->dispatch('toast', message: __('warranty.document_saved'));
    }

    protected function assertSavedForm(): void
    {
        $policy = WarrantyPolicy::query()->findOrFail($this->policyId);
        foreach (self::FIELDS as $field) {
            $saved = $policy->getAttribute($field);
            if (in_array($field, ['valid_from', 'valid_until'], true)) {
                $saved = $saved?->format('Y-m-d');
            }
            if (in_array($field, ['source_confirmed', 'requires_original'], true)) {
                $same = (bool) $saved === (bool) ($this->form[$field] ?? false);
            } else {
                $same = (string) ($saved ?? '') === (string) ($this->form[$field] ?? '');
            }
            if (! $same) {
                throw ValidationException::withMessages(['form' => __('warranty.save_before_approve')]);
            }
        }
    }

    protected function policySummary(WarrantyPolicy $policy): array
    {
        $missing = [];
        $hasTerm = $policy->term_value > 0 && in_array($policy->term_unit, ['days', 'months', 'years'], true);
        foreach ([
            'period' => $hasTerm,
            'issuer' => filled($policy->issuer_name) && filled($policy->issuer_address) && filled($policy->issuer_contact),
            'territory' => filled($policy->territory),
            'coverage' => filled($policy->coverage),
            'start_basis' => in_array($policy->start_basis, ['sale_date', 'delivery_date', 'manufacturer_date'], true),
            'claim_instructions' => filled($policy->claim_instructions),
            'service' => filled($policy->service_name) && filled($policy->service_address)
                && (filled($policy->service_phone) || filled($policy->service_email)),
            'source' => filled($policy->source_reference),
            'confirmation' => $policy->source_confirmed,
        ] as $group => $present) {
            if (! $present) {
                $missing[] = __('warranty.missing.'.$group);
            }
        }
        return ['period' => $hasTerm ? $policy->term_value.' '.__('warranty.units.'.$policy->term_unit) : __('warranty.fact_missing'),
            'service' => $policy->service_name ?: __('warranty.fact_missing'), 'missing' => $missing];
    }

    public function render()
    {
        $this->warrantyActor(true);
        $policyQuery = WarrantyPolicy::query()->with(['supplier:id,name,code', 'product' => fn (BelongsTo $query) => $query->withTranslation(),
            'product.offers:id,product_id,supplier_id,external_id'])
            ->when(ctype_digit($this->supplierFilter), fn (Builder $query) => $query->where('supplier_id', (int) $this->supplierFilter))
            ->when(ctype_digit($this->productFilter), fn (Builder $query) => $query->where('product_id', (int) $this->productFilter));
        $term = mb_substr(trim($this->policySearch), 0, 120);
        if ($term !== '') {
            $policyQuery->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', '%'.$term.'%')->orWhere('source_reference', 'like', '%'.$term.'%')
                    ->orWhereHas('product', fn (Builder $product) => $product->where('sku', 'like', '%'.$term.'%')
                        ->orWhereHas('translations', fn (Builder $translations) => $translations->where('name', 'like', '%'.$term.'%')))
                    ->orWhereHas('product.offers', fn (Builder $offers) => $offers
                        ->whereColumn('product_offers.supplier_id', 'warranty_policies.supplier_id')
                        ->where('external_id', 'like', '%'.$term.'%'));
                if (ctype_digit($term)) {
                    $query->orWhere('product_id', (int) $term);
                }
            });
        }
        $policies = $policyQuery->orderByDesc('id')->paginate(20);
        $policy = $this->policyId === null ? null : WarrantyPolicy::query()->with('documents')->find($this->policyId);
        $productMatches = collect();
        if (mb_strlen(trim($this->productSearch)) >= 2) {
            $term = mb_substr(trim($this->productSearch), 0, 120);
            $productMatches = Product::query()->withTranslation()
                ->where(fn (Builder $query) => $query->where('sku', 'like', '%'.$term.'%')
                    ->orWhereHas('translations', fn (Builder $translations) => $translations->where('name', 'like', '%'.$term.'%')))
                ->orderBy('id')->limit(8)->get();
        }
        $suppliers = Supplier::query()->orderBy('name')->get(['id', 'name', 'code']);
        $supplierCode = $suppliers->firstWhere('id', (int) ($this->form['supplier_id'] ?? 0))?->code;
        $registryPath = resource_path('warranty/research.json');
        $registry = is_readable($registryPath) ? json_decode(file_get_contents($registryPath), true) : [];
        $research = collect(is_array($registry) ? ($registry['suppliers'] ?? []) : [])
            ->firstWhere('code', $supplierCode);
        if (is_array($research)) {
            $research['source_urls'] = array_values(array_filter($research['source_urls'] ?? [],
                fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                    && parse_url($url, PHP_URL_SCHEME) === 'https'));
        }

        return view('livewire.admin.warranties.policies', [
            'policies' => $policies,
            'policySummaries' => $policies->getCollection()->mapWithKeys(fn (WarrantyPolicy $row) => [$row->id => $this->policySummary($row)])->all(),
            'policy' => $policy,
            'selectedSummary' => $policy ? $this->policySummary($policy) : null,
            'suppliers' => $suppliers,
            'research' => $research,
            'brands' => Brand::query()->orderBy('name')->get(),
            'categories' => Category::query()->withTranslation()->orderBy('sort_order')->get(),
            'productMatches' => $productMatches,
            'selectedProduct' => empty($this->form['product_id']) ? null : Product::query()->withTranslation()->find($this->form['product_id']),
        ])->layout('layouts.admin', ['title' => __('warranty.policies_title')]);
    }
}
