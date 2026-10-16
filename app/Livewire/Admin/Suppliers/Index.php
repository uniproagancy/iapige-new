<?php

namespace App\Livewire\Admin\Suppliers;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public int $priority = 50;

    public bool $is_active = true;

    public string $markup = '';

    public string $config = '';

    public function render()
    {
        return view('livewire.admin.suppliers.index', [
            'suppliers' => $this->suppliers(),
        ])->layout('layouts.admin', ['title' => __('admin.suppliers')]);
    }

    protected function suppliers()
    {
        $rows = SupplierStock::selectRaw('supplier_id, count(*) as total, sum(quantity > 0) as in_stock, max(synced_at) as synced')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $offers = ProductOffer::selectRaw('supplier_id, count(*) as offers, sum(stock > 0) as sellable')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        return Supplier::orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier) use ($rows, $offers) {
                $stock = $rows->get($supplier->id);
                $offer = $offers->get($supplier->id);
                $columns = $supplier->config['columns'] ?? [];

                return [
                    'model' => $supplier,
                    'rows' => (int) ($stock->total ?? 0),
                    'in_stock' => (int) ($stock->in_stock ?? 0),
                    'synced' => $stock->synced ?? null,
                    'offers' => (int) ($offer->offers ?? 0),
                    'sellable' => (int) ($offer->sellable ?? 0),
                    'takes_file' => ! empty($columns['key']),
                    'no_stock_column' => ! empty($columns['key']) && empty($columns['stock']),
                    'scans' => (bool) ($supplier->config['scan'] ?? false),
                    'min_cost' => (float) ($supplier->config['min_cost'] ?? 0),
                ];
            });
    }

    public function toggleActive(int $id): void
    {
        $supplier = Supplier::findOrFail($id);

        $supplier->update(['is_active' => ! $supplier->is_active]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function queue(int $id, ImportManager $manager): void
    {
        $supplier = Supplier::findOrFail($id);

        try {
            $queued = $manager->run($supplier);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: __('admin.import_queued', ['count' => $queued]));
    }

    public function productCount(int $id): int
    {
        return Product::whereHas('offers', fn ($q) => $q->where('supplier_id', $id))->count();
    }
}
