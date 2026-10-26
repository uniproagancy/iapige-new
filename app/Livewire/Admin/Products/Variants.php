<?php

namespace App\Livewire\Admin\Products;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Language;
use App\Models\Product;
use App\Support\Catalog;
use App\Support\Slug;
use Livewire\Component;

/*
 | The family a product belongs to, and which of its options this one is.
 |
 | Two things make the pickers on a product page: a variant_group, which says
 | these products are the same thing in different guises, and the values each
 | of them holds for the attributes marked as variants. Both arrived only from
 | an import until now, so a wrong one could not be put right by hand.
 */
class Variants extends Component
{
    public Product $product;

    public string $group = '';

    /* attribute id => the value this product holds, or '' for none */
    public array $values = [];

    public string $find = '';

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->group = (string) $product->variant_group;

        $this->values = $this->offered()
            ->mapWithKeys(fn (Attribute $a) => [$a->id => (string) ($this->held()[$a->id] ?? '')])
            ->all();
    }

    /*
     | The attributes this product can be told apart by: the ones marked as
     | variants and kept to its shelf, or to no shelf at all.
     */
    public function offered()
    {
        return Attribute::where('is_variant', true)
            ->with(['translations', 'categories:id', 'values.translations'])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Attribute $a) => $a->appliesTo($this->product->category))
            ->values();
    }

    /**
     * attribute id => value id, for what this product holds now.
     *
     * @return array<int, int>
     */
    protected function held(): array
    {
        return $this->product->attributeValues()
            ->with('attribute')
            ->get()
            ->filter(fn (AttributeValue $v) => $v->attribute)
            ->mapWithKeys(fn (AttributeValue $v) => [$v->attribute_id => $v->id])
            ->all();
    }

    /*
     | Everyone in the family, this product among them. Without a group of its
     | own a product keeps the old rule -- its own sku and nothing else -- so
     | it stands alone until one is given.
     */
    public function family()
    {
        if ($this->product->variant_group) {
            return Product::withTranslation()
                ->where('variant_group', $this->product->variant_group)
                ->with(['attributeValues.attribute.translations', 'attributeValues.translations'])
                ->orderBy('price')
                ->get();
        }

        return Product::withTranslation()->whereKey($this->product->id)->get();
    }

    public function saveGroup(): void
    {
        $this->validate([
            'group' => ['nullable', 'string', 'max:64', 'regex:/^[\pL\pN._-]*$/u'],
        ]);

        $was = $this->product->variant_group;

        $this->product->update(['variant_group' => trim($this->group) ?: null]);
        $this->product = $this->product->fresh();

        $this->settle($was);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    /*
     | Puts another product in this one's family. A product cannot be joined to
     | nothing, so a family without a name is given one first -- built from the
     | product's own sku, which is as good a name as any.
     */
    public function join(int $id): void
    {
        $other = Product::find($id);

        if (! $other || $other->is($this->product)) {
            return;
        }

        if (! $this->product->variant_group) {
            $this->product->update([
                'variant_group' => Slug::make($this->product->sku) ?: 'g'.$this->product->id,
            ]);

            $this->product = $this->product->fresh();
            $this->group = (string) $this->product->variant_group;
        }

        $was = $other->variant_group;

        $other->update(['variant_group' => $this->product->variant_group]);

        $this->settle($was);
        $this->reset('find');

        $this->dispatch('toast', message: __('admin.variants_joined', ['name' => $other->name]));
    }

    public function leave(int $id): void
    {
        $other = Product::find($id);

        if (! $other) {
            return;
        }

        $was = $other->variant_group;

        $other->update(['variant_group' => null]);

        if ($other->is($this->product)) {
            $this->product = $this->product->fresh();
            $this->group = '';
        }

        $this->settle($was);

        $this->dispatch('toast', message: __('admin.variants_left', ['name' => $other->name]));
    }

    public function saveValues(): void
    {
        $offered = $this->offered();

        /* Only the values of the attributes shown here are touched; everything
           else the product holds is left exactly as it was. */
        $theirs = AttributeValue::whereIn('attribute_id', $offered->pluck('id'))->pluck('id');

        $keep = $this->product->attributeValues()
            ->pluck('attribute_values.id')
            ->diff($theirs);

        $chosen = collect($this->values)
            ->filter(fn ($id) => $id !== '' && $id !== null)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $theirs->contains($id));

        $this->product->attributeValues()->sync($keep->merge($chosen)->unique()->all());

        $this->settle($this->product->variant_group);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    /*
     | Adds a value to an attribute and gives it to this product in one go, so
     | a size nobody has sold yet does not have to be made somewhere else first.
     */
    public function addValue(int $attributeId, string $label): void
    {
        $label = trim($label);

        if ($label === '') {
            return;
        }

        $attribute = $this->offered()->firstWhere('id', $attributeId);

        if (! $attribute) {
            return;
        }

        $code = Slug::make($label) ?: 'v'.now()->timestamp;

        $value = AttributeValue::firstOrCreate(
            ['attribute_id' => $attribute->id, 'code' => $code],
            ['sort_order' => (int) AttributeValue::where('attribute_id', $attribute->id)->max('sort_order') + 1],
        );

        if (! $value->translate(Language::defaultCode(), false)) {
            $value->saveTranslations([Language::defaultCode() => ['label' => $label]]);
        }

        $this->values[$attribute->id] = (string) $value->id;

        $this->saveValues();
    }

    /* Everything the catalogue remembers about these products is now wrong. */
    protected function settle(?string $alsoGroup): void
    {
        Catalog::flushTree();

        foreach (array_filter([$alsoGroup, $this->product->variant_group]) as $group) {
            Product::where('variant_group', $group)->update(['updated_at' => now()]);
        }
    }

    public function render()
    {
        $found = collect();

        if (mb_strlen(trim($this->find)) >= 2) {
            $term = trim($this->find);

            $found = Product::withTranslation()
                ->whereKeyNot($this->product->id)
                ->where(fn ($q) => $q
                    ->where('sku', 'like', "%{$term}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$term}%")))
                ->when($this->product->variant_group, fn ($q) => $q
                    ->where(fn ($w) => $w
                        ->whereNull('variant_group')
                        ->orWhere('variant_group', '!=', $this->product->variant_group)))
                ->limit(8)
                ->get();
        }

        return view('livewire.admin.products.variants', [
            'offered' => $this->offered(),
            'family' => $this->family(),
            'found' => $found,
        ]);
    }
}
