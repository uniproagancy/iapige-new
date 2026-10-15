<?php

namespace App\Livewire\Admin\Import;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Citrus\CitrusLinks;
use App\Services\Import\ImportManager;
use Livewire\Component;

/**
 * Adding products by pasting their addresses.
 *
 * Some suppliers have no price list at all: you find the product on their
 * site and you want that one. Doing it one at a time means opening the
 * product, copying the address, filling a form, submitting, waiting — forty
 * times for a page of forty products.
 *
 * So the box takes the lot. The addresses are checked, reduced to what the
 * supplier's API needs and written down; the import then runs over them like
 * any other supplier's rows, through the same queue with the same retries.
 */
class Links extends Component
{
    public string $supplierCode = '';

    public string $links = '';

    /** An optional name for the mapping queue, when the site's own is wrong. */
    public string $category = '';

    /** @var array{added:int, updated:int, skipped:array<int, string>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->supplierCode = (string) ($this->suppliers()->first()?->code ?? '');
    }

    /**
     * Suppliers that are added to this way.
     *
     * A supplier with a column map is fed by a spreadsheet and belongs on the
     * upload screen; one without is fed by hand, which is this.
     */
    public function suppliers()
    {
        return Supplier::orderBy('name')->get()
            ->filter(fn (Supplier $s) => empty($s->config['columns']['key']))
            ->values();
    }

    public function save(CitrusLinks $links): void
    {
        $this->validate([
            'supplierCode' => ['required', 'exists:suppliers,code'],
            'links' => ['required', 'string', 'min:4', 'max:20000'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $supplier = Supplier::where('code', $this->supplierCode)->firstOrFail();

        $this->result = $links->add($supplier, $this->links, $this->category ?: null);

        if ($this->result['added'] || $this->result['updated']) {
            $this->links = '';
        }

        $this->dispatch('toast', message: __('admin.links_added', [
            'added' => $this->result['added'],
            'updated' => $this->result['updated'],
        ]));
    }

    /** Queues the import for everything this supplier holds. */
    public function run(ImportManager $manager): void
    {
        $supplier = Supplier::where('code', $this->supplierCode)->firstOrFail();

        $queued = $manager->run($supplier);

        $this->dispatch('toast', message: __('admin.import_queued', ['count' => $queued]));
    }

    public function forget(string $externalId): void
    {
        SupplierStock::where('supplier_id', Supplier::where('code', $this->supplierCode)->value('id'))
            ->where('external_id', $externalId)
            ->delete();

        $this->dispatch('toast', message: __('admin.deleted'));
    }

    public function render()
    {
        $supplier = $this->supplierCode
            ? Supplier::where('code', $this->supplierCode)->first()
            : null;

        return view('livewire.admin.import.links', [
            'suppliers' => $this->suppliers(),
            'rows' => $supplier
                ? SupplierStock::where('supplier_id', $supplier->id)->latest('id')->limit(50)->get()
                : collect(),
            'total' => $supplier
                ? SupplierStock::where('supplier_id', $supplier->id)->count()
                : 0,
        ])->layout('layouts.admin', ['title' => __('admin.links_title')]);
    }
}
