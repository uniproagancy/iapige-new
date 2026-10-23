<?php

namespace App\Livewire\Admin\Import;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\Drivers\Citrus\CitrusLinks;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class Links extends Component
{
    public string $supplierCode = '';

    public string $links = '';

    public string $category = '';

    public ?array $result = null;

    public bool $running = false;

    public bool $refreshing = false;

    public int $done = 0;

    public int $failed = 0;

    public int $total = 0;

    public string $current = '';

    /* Why each address came back empty-handed -- "3 failed" on its own sends
       nobody anywhere. */
    public array $failures = [];

    public function mount(): void
    {
        $this->supplierCode = (string) ($this->suppliers()->first()?->code ?? '');
    }

    public function suppliers()
    {
        return Supplier::orderBy('name')->get()
            ->filter(fn (Supplier $s) => empty($s->config['columns']['key']))
            ->values();
    }

    public function updatedSupplierCode(): void
    {
        $this->reset(['result', 'running', 'done', 'failed', 'total', 'current', 'failures']);
    }

    public function save(CitrusLinks $links): void
    {
        $this->validate([
            'supplierCode' => ['required', 'exists:suppliers,code'],
            'links' => ['required', 'string', 'min:4', 'max:20000'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $this->result = $links->add($this->supplier(), $this->links, $this->category ?: null);

        if ($this->result['added'] || $this->result['updated']) {
            $this->links = '';
        }

        $this->dispatch('toast', message: __('admin.links_added', ['count' => $this->result['added']]));
    }

    public function start(bool $refresh = false): void
    {
        $this->refreshing = $refresh;
        $this->done = 0;
        $this->failed = 0;
        $this->current = '';
        $this->failures = [];
        $this->total = $this->pending()->count();
        $this->running = $this->total > 0;

        if (! $this->running) {
            $this->dispatch('toast', message: __('admin.links_nothing_to_fetch'));
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->current = '';
    }

    public function fetchNext(ImportManager $manager, ProductImporter $importer): void
    {
        if (! $this->running) {
            return;
        }

        $row = $this->pending()->first();

        if (! $row) {
            $this->running = false;
            $this->current = '';

            $this->dispatch('toast', message: __('admin.links_finished', [
                'done' => $this->done,
                'failed' => $this->failed,
            ]));

            return;
        }

        $this->current = $row->external_id;

        $supplier = $this->supplier();

        try {
            $payload = $manager->driver($supplier)->fetch($row->external_id);

            /* The address was pasted in by hand, so an empty shelf is no reason
               to refuse the product. */

            if (! $payload) {
                $this->lost($supplier, $row->external_id, __('admin.links_reason_page'));
            } elseif (! $importer->import($supplier, $payload, insist: true)) {
                $this->lost($supplier, $row->external_id, __('admin.links_reason_refused'));
            } else {
                $this->done++;
            }
        } catch (\Throwable $e) {
            $this->lost($supplier, $row->external_id, Redact::secrets($e->getMessage()));
        }
    }

    /* The page keeps only the first handful: the point is to show what kind of
       address is failing, not to list a thousand of them. */
    protected function lost(Supplier $supplier, string $externalId, string $why): void
    {
        $this->failed++;

        if (count($this->failures) < 12) {
            $this->failures[$externalId] = $why;
        }

        Log::channel('import')->warning('link import failed', [
            'supplier' => $supplier->code,
            'external' => $externalId,
            'error' => $why,
        ]);
    }

    public function forget(string $externalId): void
    {
        SupplierStock::where('supplier_id', $this->supplier()->id)
            ->where('external_id', $externalId)
            ->delete();

        $this->dispatch('toast', message: __('admin.deleted'));
    }

    protected function pending()
    {
        $supplier = $this->supplier();

        $query = SupplierStock::where('supplier_id', $supplier->id)->orderBy('id');

        if ($this->refreshing) {
            return $query;
        }

        return $query->whereNotIn(
            'external_id',
            ProductOffer::where('supplier_id', $supplier->id)->select('external_id'),
        );
    }

    protected function supplier(): Supplier
    {
        return Supplier::where('code', $this->supplierCode)->firstOrFail();
    }

    public function render()
    {
        $supplier = $this->supplierCode
            ? Supplier::where('code', $this->supplierCode)->first()
            : null;

        /* The list links each pasted address to the product it became, so the
           view needs the product itself -- the id alone cannot name a page. */
        $held = $supplier
            ? ProductOffer::where('supplier_id', $supplier->id)
                ->with(['product' => fn ($q) => $q->withTranslation()])
                ->get()
                ->mapWithKeys(fn (ProductOffer $offer) => [$offer->external_id => $offer->product])
                ->filter()
            : collect();

        return view('livewire.admin.import.links', [
            'suppliers' => $this->suppliers(),
            'rows' => $supplier
                ? SupplierStock::where('supplier_id', $supplier->id)->latest('id')->limit(60)->get()
                : collect(),
            'held' => $held,
            'count' => $supplier ? SupplierStock::where('supplier_id', $supplier->id)->count() : 0,
            'waiting' => $supplier && ! $this->running ? $this->pending()->count() : 0,
        ])->layout('layouts.admin', ['title' => __('admin.links_title')]);
    }
}
