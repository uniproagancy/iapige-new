<?php

namespace App\Livewire\Admin\Suppliers;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaStockFeed;
use App\Services\Import\ImportManager;
use App\Services\Import\SupplierFloor;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public int $priority = 50;

    public bool $is_active = true;

    public string $markup = '';

    public string $config = '';

    /** Supplier id => the floor typed into its row. */
    public array $floors = [];

    public function mount(): void
    {
        $this->floors = Supplier::all()
            ->mapWithKeys(fn (Supplier $supplier) => [
                $supplier->id => SupplierFloor::of($supplier) > 0 ? (string) SupplierFloor::of($supplier) : '',
            ])
            ->all();
    }

    public function render()
    {
        $alta = Supplier::where('code', 'alta')->where('is_active', true)
            ->where('driver', AltaDriver::class)->first();

        return view('livewire.admin.suppliers.index', [
            'suppliers' => $this->suppliers(),
            'altaFeedAvailable' => $alta !== null,
            'altaFeedUrl' => $alta ? app(AltaStockFeed::class)->link($alta) : null,
        ])->layout('layouts.admin', ['title' => __('admin.suppliers')]);
    }

    public function createAltaStockLink(AltaStockFeed $feed): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $supplier = Supplier::where('code', 'alta')->where('is_active', true)
            ->where('driver', AltaDriver::class)->firstOrFail();

        $feed->rotateLink($supplier);
        $this->dispatch('toast', message: __('admin.alta_stock_link_created'));
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

        $floor = app(SupplierFloor::class);

        return Supplier::orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier) use ($rows, $offers, $floor) {
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
                    'min_cost' => SupplierFloor::of($supplier),
                    'below' => SupplierFloor::of($supplier) > 0 ? $floor->below($supplier)->count() : 0,
                ];
            });
    }

    public function toggleActive(int $id): void
    {
        $supplier = Supplier::findOrFail($id);

        $supplier->update(['is_active' => ! $supplier->is_active]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function saveFloor(int $id, SupplierFloor $floor): void
    {
        Gate::authorize('owner');

        $this->validate(
            ["floors.$id" => 'nullable|numeric|min:0|max:1000000'],
            attributes: ["floors.$id" => __('admin.sup_floor_label')],
        );

        $floor->set(Supplier::findOrFail($id), (float) ($this->floors[$id] ?? 0));

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function purgeBelowFloor(int $id, SupplierFloor $floor): void
    {
        Gate::authorize('owner');

        $done = $floor->purge(Supplier::findOrFail($id));

        $this->dispatch('toast', message: __('admin.sup_purged', $done));
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
