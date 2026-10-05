<?php

namespace App\Livewire\Product;

use App\Models\Product;
use App\Support\Catalog;
use Livewire\Component;

/**
 * "Cheaper together" — the product plus suggested extras. The first row is the
 * product itself and cannot be unticked.
 */
class Bundle extends Component
{
    /** @var array<int, int> product ids, the first one being the page's product */
    public array $ids = [];

    public array $selected = [];

    public function mount(array $ids): void
    {
        $this->ids = array_values($ids);
        $this->selected = $this->ids;
    }

    public function toggle(int $id): void
    {
        if ($id === ($this->ids[0] ?? null)) {
            return; // the product itself always stays in
        }

        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : array_values(array_intersect($this->ids, [...$this->selected, $id]));
    }

    public function buy(): void
    {
        foreach ($this->selected as $id) {
            $this->dispatch('cart-add', productId: $id, qty: 1);
        }
    }

    public function render()
    {
        $products = Product::withTranslation()->with(['brand', 'images'])
            ->whereIn('id', $this->ids)->get()
            ->sortBy(fn ($p) => array_search($p->id, $this->ids))
            ->values();

        $rows = $products->map(fn (Product $p) => Catalog::card($p) + [
            'selected' => in_array($p->id, $this->selected, true),
            'fixed' => $p->id === ($this->ids[0] ?? null),
        ])->all();

        $chosen = array_filter($rows, fn ($r) => $r['selected']);

        return view('livewire.product.bundle', [
            'rows' => $rows,
            'count' => count($chosen),
            'total' => array_sum(array_column($chosen, 'price')),
            'old' => array_sum(array_map(fn ($r) => $r['old'] ?: $r['price'], $chosen)),
        ]);
    }
}
