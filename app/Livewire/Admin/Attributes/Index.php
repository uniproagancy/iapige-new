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

/**
 * Which specs become filters.
 *
 * Every spec the suppliers send becomes an attribute on its own, because a
 * spec we cannot show is a loss. A *filter* is the opposite: twenty sidebar
 * groups help nobody, so nothing is filterable until somebody decides it here.
 */
class Index extends Component
{
    use WithPagination;

    /** The sidebar builds these itself; enabling them would draw a second group. */
    protected const RESERVED = ['brand'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' | on | off | unused */
    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    /** products | values | name */
    #[Url(as: 'sort', except: 'products')]
    public string $sort = 'products';

    /* ---- editing one attribute ---- */
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $type = 'select';

    public int $sort_order = 100;

    public bool $is_filterable = false;

    public bool $is_variant = false;

    /** ['ka' => ['name' => …], 'en' => …] */
    public array $translations = [];

    /* ---- values of one attribute ---- */
    public ?int $valuesFor = null;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'stateFilter', 'sort'], true)) {
            $this->resetPage();
            $this->valuesFor = null;
        }
    }

    /* ------------------------------------------------------------------ list */

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

        // sorting by reach happens here: the count comes from a second query, so
        // it can only order the page in hand — enough, since the page is 50 rows
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

    /**
     * How many products carry each attribute — the number that says whether a
     * filter is worth showing at all.
     *
     * @return array<int, int>
     */
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

    /* ------------------------------------------------------------------ toggles */

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

    /** Turn on every attribute that actually reaches enough products. */
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

    /* ------------------------------------------------------------------ form */

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
            // a reserved code can never be a filter, whatever the form says
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

    /* ------------------------------------------------------------------ values */

    public function showValues(int $id): void
    {
        $this->valuesFor = $this->valuesFor === $id ? null : $id;
    }

    /** A value nobody uses is noise in the sidebar. */
    public function deleteValue(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('attribute_value_product')->where('attribute_value_id', $id)->delete();
            AttributeValue::whereKey($id)->delete();
        });

        $this->bumpCatalog();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /* ------------------------------------------------------------------ delete */

    public function delete(int $id): void
    {
        $attribute = Attribute::findOrFail($id);

        DB::transaction(function () use ($attribute) {
            $valueIds = AttributeValue::where('attribute_id', $attribute->id)->pluck('id');

            DB::table('attribute_value_product')->whereIn('attribute_value_id', $valueIds)->delete();
            AttributeValue::whereIn('id', $valueIds)->delete();

            // the specs that used it would point at nothing
            DB::table('product_specs')->where('attribute_id', $attribute->id)->delete();

            // and the mapping would recreate it on the next import
            DB::table('supplier_attribute_map')->where('attribute_id', $attribute->id)->delete();

            $attribute->delete();
        });

        $this->bumpCatalog();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /** The storefront caches its filter groups; a change here has to reach it. */
    protected function bumpCatalog(): void
    {
        method_exists(Catalog::class, 'bumpVersion')
            ? Catalog::bumpVersion()
            : cache()->forget('catalog:groups');
    }
}
