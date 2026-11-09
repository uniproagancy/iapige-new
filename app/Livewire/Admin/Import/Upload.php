<?php

namespace App\Livewire\Admin\Import;

use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Services\Import\PriceListLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

    public ?string $batch = null;

    /* The supplier a file is loaded into is asked for once more before it
       is, by name: the buttons sit side by side, and a price list loaded into
       the wrong one replaced that supplier's prices and stock with another's. */
    public bool $confirming = false;

    public ?string $confirmingCode = null;

    public function mount(): void
    {
        $this->code = $this->code ?: (string) ($this->suppliers()->first()?->code ?? '');
    }

    public function render()
    {
        $supplier = $this->supplier();

        return view('livewire.admin.import.upload', [
            'otherSupplier' => $this->confirming && $supplier ? $this->otherSupplierNamed($supplier) : null,
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

        $this->reset(['file', 'result', 'mode', 'batch', 'confirming', 'confirmingCode']);
    }

    /* The file is checked first, so the question is only put about a load
       that can actually happen. */
    public function confirmLoad(): void
    {
        $this->validate(self::loadRules());

        if (! $this->supplier()) {
            return;
        }

        $this->confirmingCode = $this->code;
        $this->confirming = true;
    }

    public function cancelLoad(): void
    {
        $this->reset(['confirming', 'confirmingCode']);
    }

    public function load(PriceListLoader $loader): void
    {
        /* Only a load that was confirmed, and only into the supplier it was
           confirmed for. */
        $confirmed = $this->confirming && $this->confirmingCode === $this->code;

        $this->reset(['confirming', 'confirmingCode']);

        if (! $confirmed) {
            return;
        }

        $this->validate(self::loadRules());

        if (! $supplier = $this->supplier()) {
            return;
        }

        try {
            $result = $loader->load(
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

        $this->batch = null;

        if ($result['mode'] === PriceListLoader::MODE_MERGE && $result['codes']) {
            $this->batch = (string) Str::uuid();

            Cache::put(self::batchKey($this->batch), $result['codes'], now()->addHours(6));
        }

        unset($result['codes']);

        $this->result = $result;

        $this->reset('file');

        $this->dispatch('toast', message: __('admin.import_rows_loaded', ['count' => $this->result['read']]));
    }

    public function queueImport(ImportManager $manager): void
    {
        if (! $supplier = $this->supplier()) {
            return;
        }

        $queued = $manager->run($supplier, $this->justLoaded());

        $this->dispatch('toast', message: __('admin.import_queued', ['count' => $queued]));
    }

    protected function justLoaded(): ?array
    {
        return $this->batch ? Cache::get(self::batchKey($this->batch)) : null;
    }

    protected static function batchKey(string $batch): string
    {
        return 'import:merged:'.$batch;
    }

    protected static function loadRules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'sheet' => ['integer', 'min:1'],
            'skipRows' => ['integer', 'min:0', 'max:20'],

            'mode' => ['required', 'in:'.implode(',', PriceListLoader::MODES)],
        ];
    }

    /*
     | Another supplier the file's own name speaks of -- "kontakt_10-11.xlsx"
     | about to go into MagiToys -- when it does not name the one chosen.
     */
    protected function otherSupplierNamed(Supplier $chosen): ?string
    {
        $file = $this->file && method_exists($this->file, 'getClientOriginalName')
            ? mb_strtolower(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME))
            : '';

        if ($file === '') {
            return null;
        }

        $names = fn (Supplier $s) => array_filter(
            [mb_strtolower($s->code), mb_strtolower((string) $s->name)],
            fn (string $word) => mb_strlen($word) >= 3,
        );

        $mentions = fn (Supplier $s) => (bool) array_filter($names($s), fn (string $word) => str_contains($file, $word));

        if ($mentions($chosen)) {
            return null;
        }

        return Supplier::where('id', '!=', $chosen->id)->get()->first($mentions)?->name;
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
