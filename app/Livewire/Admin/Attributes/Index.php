<?php

namespace App\Livewire\Admin\Attributes;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Language;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    protected const RESERVED = ['brand'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'sort', except: 'products')]
    public string $sort = 'products';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $type = 'select';

    public int $sort_order = 100;

    public bool $is_filterable = false;

    public bool $is_variant = false;

    public array $translations = [];

    public ?int $valuesFor = null;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'stateFilter', 'sort'], true)) {
            $this->resetPage();
            $this->valuesFor = null;
        }
    }

    public function render()
    {
        $usage = $this->usage();

        $rows = Attribute::withTranslation()
            ->withCount('values')
            ->when($this->search, function ($q) {
                $term = $this->search;

                $q->where(fn ($w) => $w
                    ->where('code', 'like', "%{$term}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$term}%")));
            })
            ->when($this->stateFilter === 'on', fn ($q) => $q->where('is_filterable', true))
            ->when($this->stateFilter === 'off', fn ($q) => $q->where('is_filterable', false))
            ->when($this->stateFilter === 'unused', fn ($q) => $q->whereDoesntHave('values'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('code'))
            ->when($this->sort === 'values', fn ($q) => $q->orderByDesc('values_count'))
            ->when($this->sort === 'products', fn ($q) => $q->orderByDesc('is_filterable')->orderBy('sort_order'))
            ->paginate(50);

        if ($this->sort === 'products') {
            $rows->setCollection(
                $rows->getCollection()
                    ->sortByDesc(fn (Attribute $a) => [$a->is_filterable ? 1 : 0, $usage[$a->id] ?? 0])
                    ->values()
            );
        }

        return view('livewire.admin.attributes.index', [
            'rows' => $rows,
            'usage' => $usage,
            'languages' => Language::active(),
            'reserved' => self::RESERVED,
            'counts' => [
                'total' => Attribute::count(),
                'on' => Attribute::where('is_filterable', true)->count(),
            ],
            'values' => $this->valuesFor
                ? AttributeValue::where('attribute_id', $this->valuesFor)
                    ->withTranslation()->orderBy('sort_order')->orderBy('code')->get()
                : collect(),
        ])->layout('layouts.admin', ['title' => __('admin.attributes')]);
    }

    protected function usage(): array
    {
        return DB::table('attribute_value_product as avp')
            ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
            ->selectRaw('av.attribute_id, count(distinct avp.product_id) as n')
            ->groupBy('av.attribute_id')
            ->pluck('n', 'attribute_id')
            ->all();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'stateFilter']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->stateFilter !== '';
    }

    public function toggleFilterable(int $id): void
    {
        $attribute = Attribute::findOrFail($id);

        if (in_array($attribute->code, self::RESERVED, true)) {
            $this->dispatch('toast', message: __('admin.attribute_reserved'), type: 'error');

            return;
        }

        $attribute->update(['is_filterable' => ! $attribute->is_filterable]);
        $this->bumpCatalog();
    }

    public function toggleVariant(int $id): void
    {
        $attribute = Attribute::findOrFail($id);
        $attribute->update(['is_variant' => ! $attribute->is_variant]);

        $this->bumpCatalog();
    }

    public function enableUseful(int $minProducts = 5, int $maxValues = 40): void
    {
        $usage = $this->usage();

        $ids = Attribute::withCount('values')->get()
            ->filter(fn (Attribute $a) => ! in_array($a->code, self::RESERVED, true)
                && ($usage[$a->id] ?? 0) >= $minProducts
                && $a->values_count > 1
                && $a->values_count <= $maxValues)
            ->pluck('id');

        Attribute::whereIn('id', $ids)->update(['is_filterable' => true]);
        $this->bumpCatalog();

        $this->dispatch('toast', message: __('admin.filters_enabled', ['count' => $ids->count()]));
    }

    public function disableAll(): void
    {
        Attribute::where('is_filterable', true)->update(['is_filterable' => false]);
        $this->bumpCatalog();

        $this->dispatch('toast', message: __('admin.filters_disabled'));
    }

    public function edit(int $id): void
    {
        $attribute = Attribute::with('translations')->findOrFail($id);

        $this->editingId = $attribute->id;
        $this->code = $attribute->code;
        $this->type = $attribute->type ?? 'select';
        $this->sort_order = $attribute->sort_order;
        $this->is_filterable = (bool) $attribute->is_filterable;
        $this->is_variant = (bool) $attribute->is_variant;
        $this->resetValidation();

        $this->translations = Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => ['name' => $attribute->translate($l->code, false)?->name ?? ''],
        ])->all();

        $this->showForm = true;
    }

    public function save(): void
    {
        $default = Language::defaultCode();

        $this->validate([
            'code' => ['required', 'string', 'max:60', 'unique:attributes,code,'.$this->editingId],
            "translations.{$default}.name" => ['required', 'string', 'max:120'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $attribute = Attribute::findOrFail($this->editingId);

        $attribute->update([
            'code' => $this->code,
            'type' => $this->type,
            'sort_order' => $this->sort_order,

            'is_filterable' => in_array($this->code, self::RESERVED, true) ? false : $this->is_filterable,
            'is_variant' => $this->is_variant,
        ]);

        $attribute->saveTranslations(
            collect($this->translations)
                ->filter(fn ($fields) => filled($fields['name'] ?? null))
                ->mapWithKeys(fn ($fields, $locale) => [$locale => ['name' => $fields['name']]])
                ->all()
        );

        $this->bumpCatalog();
        $this->showForm = false;
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function showValues(int $id): void
    {
        $this->valuesFor = $this->valuesFor === $id ? null : $id;
    }

    public function deleteValue(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('attribute_value_product')->where('attribute_value_id', $id)->delete();
            AttributeValue::whereKey($id)->delete();
        });

        $this->bumpCatalog();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    public function delete(int $id): void
    {
        $attribute = Attribute::findOrFail($id);

        DB::transaction(function () use ($attribute) {
            $valueIds = AttributeValue::where('attribute_id', $attribute->id)->pluck('id');

            DB::table('attribute_value_product')->whereIn('attribute_value_id', $valueIds)->delete();
            AttributeValue::whereIn('id', $valueIds)->delete();

            DB::table('product_specs')->where('attribute_id', $attribute->id)->delete();

            DB::table('supplier_attribute_map')->where('attribute_id', $attribute->id)->delete();

            $attribute->delete();
        });

        $this->bumpCatalog();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    protected function bumpCatalog(): void
    {
        method_exists(Catalog::class, 'bumpVersion')
            ? Catalog::bumpVersion()
            : cache()->forget('catalog:groups');
    }
}
