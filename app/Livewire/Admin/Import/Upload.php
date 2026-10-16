<?php

namespace App\Livewire\Admin\Import;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Services\Import\PriceListLoader;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

class Upload extends Component
{
    use WithFileUploads;

    #[Url(as: 'supplier', except: '')]
    public string $code = '';

    public $file = null;

    public int $sheet = 1;

    public int $skipRows = 1;

    public string $mode = PriceListLoader::MODE_REPLACE;

    public ?array $result = null;

    public function mount(): void
    {
        $this->code = $this->code ?: (string) ($this->suppliers()->first()?->code ?? '');
    }

    public function render()
    {
        $supplier = $this->supplier();

        return view('livewire.admin.import.upload', [
            'suppliers' => $this->suppliers(),
            'supplier' => $supplier,
            'columns' => $supplier->config['columns'] ?? [],
            'loaded' => $supplier ? SupplierStock::where('supplier_id', $supplier->id)->count() : 0,
            'inStock' => $supplier
                ? SupplierStock::where('supplier_id', $supplier->id)->where('quantity', '>', 0)->count()
                : 0,
            'lastLoad' => $supplier
                ? SupplierStock::where('supplier_id', $supplier->id)->max('synced_at')
                : null,
        ])->layout('layouts.admin', ['title' => __('admin.import_upload')]);
    }

    public function updatedCode(): void
    {

        $this->reset(['file', 'result', 'mode']);
    }

    public function load(PriceListLoader $loader): void
    {

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'sheet' => ['integer', 'min:1'],
            'skipRows' => ['integer', 'min:0', 'max:20'],

            'mode' => ['required', 'in:'.implode(',', PriceListLoader::MODES)],
        ]);

        if (! $supplier = $this->supplier()) {
            return;
        }

        try {
            $this->result = $loader->load(
                $supplier,
                $this->file->getRealPath(),
                $this->sheet,
                $this->skipRows,
                $this->mode,
            );
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->reset('file');

        $this->dispatch('toast', message: __('admin.import_rows_loaded', ['count' => $this->result['read']]));
    }

    public function queueImport(ImportManager $manager): void
    {
        if (! $supplier = $this->supplier()) {
            return;
        }

        $queued = $manager->run($supplier);

        $this->dispatch('toast', message: __('admin.import_queued', ['count' => $queued]));
    }

    protected function supplier(): ?Supplier
    {
        return $this->code ? Supplier::where('code', $this->code)->first() : null;
    }

    protected function suppliers()
    {
        return Supplier::orderBy('name')
            ->get()
            ->filter(fn (Supplier $s) => ! empty($s->config['columns']['key']))
            ->values();
    }
}
