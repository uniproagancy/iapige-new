<?php

namespace App\Livewire\Admin\Products;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpec;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    public string $sku = '';

    public string $status = Product::STATUS_DRAFT;

    public ?int $category_id = null;

    public ?int $brand_id = null;

    public bool $taxonomy_lock = false;

    public ?string $price = null;

    public ?string $old_price = null;

    public ?string $cost_price = null;

    public bool $price_lock = false;

    public int $stock = 0;

    public bool $is_preorder = false;

    public ?string $release_date = null;

    public ?string $weight = null;

    public ?string $length = null;

    public ?string $width = null;

    public ?string $height = null;

    public bool $is_bulky = false;

    public array $translations = [];

    public array $specs = [];

    public ?int $newAttribute = null;

    public string $newValue = '';

    public $upload = [];

    protected bool $justCreated = false;

    public function mount(?Product $product = null): void
    {
        $this->product = $product?->exists ? $product : null;

        $this->product
            ? $this->fillFrom($this->product)
            : $this->fillBlank();
    }

    protected function fillFrom(Product $product): void
    {
        $product->loadMissing(['translations', 'images', 'specs.attribute', 'specs.translations', 'offers.supplier']);

        $this->sku = (string) $product->sku;
        $this->status = $product->status;
        $this->category_id = $product->category_id;
        $this->brand_id = $product->brand_id;
        $this->taxonomy_lock = (bool) $product->taxonomy_lock;

        $this->price = (string) $product->price;
        $this->old_price = $product->old_price ? (string) $product->old_price : null;
        $this->cost_price = $product->cost_price ? (string) $product->cost_price : null;
        $this->price_lock = (bool) $product->price_lock;

        $this->stock = (int) $product->stock;
        $this->is_preorder = (bool) $product->is_preorder;
        $this->release_date = $product->release_date?->format('Y-m-d');

        $this->weight = $product->weight ? (string) $product->weight : null;
        $this->length = $product->length ? (string) $product->length : null;
        $this->width = $product->width ? (string) $product->width : null;
        $this->height = $product->height ? (string) $product->height : null;
        $this->is_bulky = (bool) $product->is_bulky;

        $this->translations = Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => [
                'name' => $product->translate($l->code, false)?->name ?? '',
                'slug' => $product->translate($l->code, false)?->slug ?? '',
                'summary' => $product->translate($l->code, false)?->summary ?? '',
                'description' => $product->translate($l->code, false)?->description ?? '',
            ],
        ])->all();

        $this->specs = $product->specs->mapWithKeys(fn (ProductSpec $s) => [$s->id => (string) $s->value])->all();
    }

    protected function fillBlank(): void
    {
        $this->translations = Language::active()
            ->mapWithKeys(fn (Language $l) => [$l->code => ['name' => '', 'slug' => '', 'summary' => '', 'description' => '']])
            ->all();
    }

    public function render()
    {
        return view('livewire.admin.products.form', [
            'languages' => Language::active(),
            'categories' => $this->categoryOptions(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'statuses' => [
                Product::STATUS_DRAFT => __('admin.status_draft'),
                Product::STATUS_ACTIVE => __('admin.status_active'),
                Product::STATUS_ARCHIVED => __('admin.status_archived'),
            ],
            'images' => $this->product?->images()->orderBy('sort_order')->get() ?? collect(),
            'specRows' => $this->product?->specs()->with('attribute')->orderBy('sort_order')->get() ?? collect(),
            'attributes' => Attribute::withTranslation()->orderBy('sort_order')->get(),
            'offers' => $this->product?->offers()->with('supplier')->get() ?? collect(),
        ])->layout('layouts.admin', [
            'title' => $this->product ? __('admin.edit_product') : __('admin.new_product'),
        ]);
    }

    protected function categoryOptions(): array
    {
        $byParent = Category::withTranslation()->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');
        $flat = [];

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$flat) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $flat[] = ['id' => $category->id, 'name' => $category->name ?: '—', 'depth' => $depth];
                $walk($category->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $flat;
    }

    public function save(bool $andPublish = false)
    {
        $default = Language::defaultCode();

        $this->validate(array_merge([
            'sku' => ['required', 'string', 'max:64'],
            "translations.{$default}.name" => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],

            'old_price' => ['nullable', 'numeric', 'gt:price'],
            'stock' => ['integer', 'min:0'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'release_date' => ['nullable', 'date'],
        ], $this->slugRules()));

        if (($andPublish || $this->status === Product::STATUS_ACTIVE) && ! $this->publishable()) {
            return null;
        }

        DB::transaction(function () use ($andPublish, $default) {
            $product = $this->product ?? new Product;

            $product->fill([
                'sku' => $this->sku,
                'category_id' => $this->category_id,
                'brand_id' => $this->brand_id,
                'taxonomy_lock' => $this->taxonomy_lock,
                'price' => (float) $this->price,
                'old_price' => $this->old_price !== null && $this->old_price !== '' ? (float) $this->old_price : null,
                'cost_price' => $this->cost_price !== null && $this->cost_price !== '' ? (float) $this->cost_price : null,
                'price_lock' => $this->price_lock,
                'stock' => $this->stock,
                'is_preorder' => $this->is_preorder,
                'release_date' => $this->release_date ?: null,
                'weight' => $this->weight !== null && $this->weight !== '' ? (int) $this->weight : null,
                'length' => $this->length !== null && $this->length !== '' ? (int) $this->length : null,
                'width' => $this->width !== null && $this->width !== '' ? (int) $this->width : null,
                'height' => $this->height !== null && $this->height !== '' ? (int) $this->height : null,
                'is_bulky' => $this->is_bulky,
                'status' => $andPublish ? Product::STATUS_ACTIVE : $this->status,
            ]);

            if ($product->status === Product::STATUS_ACTIVE && ! $product->published_at) {
                $product->published_at = now();
            }

            $product->save();

            $rows = [];

            foreach ($this->translations as $locale => $fields) {
                if (empty($fields['name'])) {
                    continue;
                }

                $rows[$locale] = [
                    'name' => $fields['name'],
                    'slug' => $fields['slug'] ?: Slug::make($fields['name']).'-'.$product->id,
                    'summary' => $fields['summary'] ?: null,
                    'description' => $fields['description'] ?: null,
                ];
            }

            $product->saveTranslations($rows);

            $rows = ProductSpec::whereKey(array_keys($this->specs))
                ->where('product_id', $product->id)
                ->get();

            foreach ($rows as $spec) {
                trim((string) $this->specs[$spec->id]) === ''
                    ? $spec->delete()
                    : $spec->saveTranslations([$default => ['value' => $this->specs[$spec->id]]]);
            }

            $this->justCreated = $product->wasRecentlyCreated;

            $this->product = $product->fresh();
        });

        $this->dispatch('toast', message: __('admin.saved'));

        if (! $this->justCreated) {
            return null;
        }

        return $this->redirect(route('admin.products.edit', $this->product), navigate: true);
    }

    public function saveAndPublish()
    {
        return $this->publishable() ? $this->save(andPublish: true) : null;
    }

    protected function publishable(): bool
    {
        $problems = [];

        if (! $this->category_id) {
            $problems[] = __('admin.no_category');
        }

        if ((float) $this->price <= 0) {
            $problems[] = __('admin.no_price');
        }

        if (! $problems) {
            return true;
        }

        $this->dispatch('toast', message: implode(', ', $problems), type: 'error');

        return false;
    }

    protected function slugRules(): array
    {
        $rules = [];

        foreach (array_keys($this->translations) as $locale) {
            $rules["translations.{$locale}.slug"] = [
                'nullable', 'string', 'max:255',
                Rule::unique('product_translations', 'slug')
                    ->where('locale', $locale)
                    ->ignore($this->product?->id, 'product_id'),
            ];
        }

        return $rules;
    }

    public function addSpec(): void
    {
        if (! $this->product || ! $this->newAttribute || trim($this->newValue) === '') {
            return;
        }

        $spec = ProductSpec::updateOrCreate(
            ['product_id' => $this->product->id, 'attribute_id' => $this->newAttribute],
            ['sort_order' => (int) ProductSpec::where('product_id', $this->product->id)->max('sort_order') + 1],
        );

        $spec->saveTranslations([Language::defaultCode() => ['value' => trim($this->newValue)]]);

        $this->specs[$spec->id] = trim($this->newValue);
        $this->reset(['newAttribute', 'newValue']);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function deleteSpec(int $id): void
    {
        ProductSpec::whereKey($id)->where('product_id', $this->product?->id)->delete();
        unset($this->specs[$id]);
    }

    public function uploadImages(): void
    {
        if (! $this->product || ! $this->upload) {
            return;
        }

        $this->validate(['upload.*' => ['mimes:jpg,jpeg,png,webp,avif', 'max:4096']]);

        $order = (int) ProductImage::where('product_id', $this->product->id)->max('sort_order');

        foreach ($this->upload as $file) {
            ProductImage::create([
                'product_id' => $this->product->id,
                'path' => $file->store("products/{$this->product->id}", 'public'),
                'sort_order' => ++$order,
            ]);
        }

        $this->reset('upload');
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function makeMain(int $id): void
    {
        $image = ProductImage::whereKey($id)->where('product_id', $this->product?->id)->first();

        if (! $image) {
            return;
        }

        DB::transaction(function () use ($image) {
            ProductImage::where('product_id', $image->product_id)->increment('sort_order');
            $image->update(['sort_order' => 0]);
        });
    }

    public function deleteImage(int $id): void
    {
        $image = ProductImage::whereKey($id)->where('product_id', $this->product?->id)->first();

        if (! $image) {
            return;
        }

        if (! str_starts_with($image->path, 'http')) {
            Storage::disk('public')->delete($image->path);
        }

        $image->delete();
    }
}
