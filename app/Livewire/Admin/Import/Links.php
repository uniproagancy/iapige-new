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

/**
 * Adding products by pasting their addresses.
 *
 * Some suppliers have no price list at all: you find the product on their
 * site and you want that one. Doing it one at a time means opening the
 * product, copying the address, filling a form, submitting, waiting — forty
 * times for a page of forty products.
 *
 * The box takes the lot, and the fetching happens here rather than on a queue.
 * A handful of addresses is not a nightly import: asking somebody to keep a
 * worker running for twenty products — and to open a terminal to start it —
 * costs more than the work itself.
 *
 * One product per request, so a long list cannot run into whatever limit the
 * host puts on a request, and the page says where it has got to while it goes.
 */
class Links extends Component
{
    public string $supplierCode = '';

    public string $links = '';

    /** An optional name for the mapping queue, when the site's own is wrong. */
    public string $category = '';

    /** @var array{added:int, updated:int, skipped:array<int, string>}|null */
    public ?array $result = null;

    /* ------------------------------------------------------------------ fetching */

    public bool $running = false;

    /** Everything again, rather than only what has never been fetched. */
    public bool $refreshing = false;

    public int $done = 0;

    public int $failed = 0;

    public int $total = 0;

    public string $current = '';

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

    public function updatedSupplierCode(): void
    {
        $this->reset(['result', 'running', 'done', 'failed', 'total', 'current']);
    }

    /* ------------------------------------------------------------------ the box */

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

    /* ------------------------------------------------------------------ fetching */

    /**
     * Starts fetching, without doing any of it yet.
     *
     * The first product is fetched by the next request, so the button answers
     * at once and the page can show what it is doing instead of appearing to
     * hang for a minute.
     */
    public function start(bool $refresh = false): void
    {
        $this->refreshing = $refresh;
        $this->done = 0;
        $this->failed = 0;
        $this->current = '';
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

    /**
     * One product, then back to the browser.
     *
     * Deliberately one: a page of forty addresses is forty seconds of
     * fetching, which is longer than many hosts allow a single request to
     * live. Doing them one per request means no list is too long, and the
     * count on the screen is honest rather than a guess.
     */
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

            if ($payload && $importer->import($supplier, $payload)) {
                $this->done++;
            } else {
                $this->failed++;
            }
        } catch (\Throwable $e) {
            $this->failed++;

            Log::channel('import')->warning('link import failed', [
                'supplier' => $supplier->code,
                'external' => $row->external_id,
                'error' => Redact::secrets($e->getMessage()),
            ]);
        }
    }

    public function forget(string $externalId): void
    {
        SupplierStock::where('supplier_id', $this->supplier()->id)
            ->where('external_id', $externalId)
            ->delete();

        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /* ------------------------------------------------------------------ what is left */

    /**
     * The addresses still to fetch.
     *
     * An address we already hold a product for is finished, unless the whole
     * lot is being refreshed — which is how a price or a photograph that has
     * changed on their side is picked up.
     */
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

        $held = $supplier
            ? ProductOffer::where('supplier_id', $supplier->id)->pluck('product_id', 'external_id')
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
