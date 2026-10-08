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

/**
 * A price list, uploaded rather than left on the server by hand.
 *
 * import:file reads a path, so until now a new spreadsheet meant opening an
 * FTP client, finding the right directory and then reaching for a terminal —
 * for a file that arrives by email every week.
 *
 * The reading is PriceListLoader's, the same code the command runs, so the
 * two cannot drift. What this adds is the part a person needs: which
 * suppliers can take a file at all, what the last one did, and the button
 * that queues the import afterwards.
 */
class Upload extends Component
{
    use WithFileUploads;

    #[Url(as: 'supplier', except: '')]
    public string $code = '';

    public $file = null;

    public int $sheet = 1;

    public int $skipRows = 1;

    /** @var array{read:int, zeroed:int, missing_url:int}|null */
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
        $this->reset(['file', 'result']);
    }

    public function load(PriceListLoader $loader): void
    {
        /*
         * mimes, not the file size alone: a spreadsheet is the only thing this
         * screen does anything with, and an "image" or "file" rule would let
         * through whatever else was dragged onto it by mistake.
         */
        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'sheet' => ['integer', 'min:1'],
            'skipRows' => ['integer', 'min:0', 'max:20'],
        ]);

        if (! $supplier = $this->supplier()) {
            return;
        }

        /*
         * Read from the temporary upload rather than a copy of our own: the
         * price list is this week's truth and nothing after the load reads it
         * again, so keeping it would only fill the disk.
         */
        try {
            $this->result = $loader->load($supplier, $this->file->getRealPath(), $this->sheet, $this->skipRows);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->reset('file');

        $this->dispatch('toast', message: __('admin.import_rows_loaded', ['count' => $this->result['read']]));
    }

    /** Queue the products themselves, which is the step the command prints. */
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

    /**
     * Only the suppliers that read a file.
     *
     * The others fetch over the network and have nothing to upload, so
     * offering them here would only invite the question of why it does
     * nothing.
     */
    protected function suppliers()
    {
        return Supplier::orderBy('name')
            ->get()
            ->filter(fn (Supplier $s) => ! empty($s->config['columns']['key']))
            ->values();
    }
}
