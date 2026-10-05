<?php

namespace App\Livewire\Admin\Brands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Brands are not translated — the name is the same everywhere — so this is a
 * plain list with an inline form, plus a merge action for the duplicates an
 * import inevitably creates ("Samsung" / "SAMSUNG").
 */
class Index extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /* ---- form ---- */
    public string $name = '';

    public string $slug = '';

    public bool $is_active = true;

    public bool $is_featured = false;

    public int $sort_order = 0;

    public $logo = null;

    public ?string $currentLogo = null;

    /* ---- merge ---- */
    public bool $showMerge = false;

    public ?int $mergeFrom = null;

    public ?int $mergeInto = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $brands = Brand::withCount('products')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(40);

        return view('livewire.admin.brands.index', [
            'brands' => $brands,
            'all' => Brand::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => __('admin.brands')]);
    }

    /* ------------------------------------------------------------------ form */

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $brand = Brand::findOrFail($id);

        $this->editingId = $brand->id;
        $this->name = $brand->name;
        $this->slug = $brand->slug;
        $this->is_active = $brand->is_active;
        $this->is_featured = $brand->is_featured;
        $this->sort_order = $brand->sort_order;
        $this->currentLogo = $brand->logoUrl();
        $this->logo = null;

        $this->showForm = true;
    }

    public function updatedName(): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:80', 'unique:brands,slug'.($this->editingId ? ",{$this->editingId}" : '')],
            'sort_order' => ['integer', 'min:0'],
            'logo' => ['nullable', 'image', 'max:1024'],
        ]);

        $brand = $this->editingId ? Brand::findOrFail($this->editingId) : new Brand;

        $brand->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,
        ]);

        if ($this->logo) {
            $brand->logo = $this->logo->store('brands', 'public');
        }

        $brand->save();

        $this->showForm = false;
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function toggleActive(int $id): void
    {
        $brand = Brand::findOrFail($id);
        $brand->update(['is_active' => ! $brand->is_active]);
    }

    public function toggleFeatured(int $id): void
    {
        $brand = Brand::findOrFail($id);
        $brand->update(['is_featured' => ! $brand->is_featured]);
    }

    public function delete(int $id): void
    {
        if (Product::where('brand_id', $id)->exists()) {
            $this->dispatch('toast', message: __('admin.brand_has_products'), type: 'error');

            return;
        }

        Brand::findOrFail($id)->delete();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /* ------------------------------------------------------------------ merge duplicates */

    public function startMerge(int $id): void
    {
        $this->mergeFrom = $id;
        $this->mergeInto = null;
        $this->showMerge = true;
    }

    /** Move every product onto the kept brand, then drop the duplicate. */
    public function merge(): void
    {
        $this->validate([
            'mergeFrom' => ['required', 'exists:brands,id'],
            'mergeInto' => ['required', 'exists:brands,id', 'different:mergeFrom'],
        ]);

        Product::where('brand_id', $this->mergeFrom)->update(['brand_id' => $this->mergeInto]);
        Brand::whereKey($this->mergeFrom)->delete();

        $this->showMerge = false;
        $this->dispatch('toast', message: __('admin.merged'));
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->slug = '';
        $this->is_active = true;
        $this->is_featured = false;
        $this->sort_order = 0;
        $this->logo = null;
        $this->currentLogo = null;
        $this->resetValidation();
    }
}
