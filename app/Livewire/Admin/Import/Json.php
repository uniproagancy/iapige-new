<?php

namespace App\Livewire\Admin\Import;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaJson;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 | Adding a product from an answer pasted in by hand.
 |
 | Some suppliers' shop fronts will not answer this server, but they answer a
 | browser perfectly well. Rather than leave those products out, the answer is
 | pasted here and goes through the same importer as everything else -- the
 | same taxonomy, the same pictures, the same offer.
 |
 | Nothing is written until it has been looked at: the first pass only reads.
 */
class Json extends Component
{
    #[Url(as: 'supplier', except: '')]
    public string $code = '';

    public string $json = '';

    /* What the paste would become, shown before anything is written. */
    public ?array $preview = null;

    /* Not $errors: that name belongs to Blade's own error bag, and a
       property of the same name hides it from every @error in the view. */
    public array $problems = [];

    /* Both are the operator's to change before saving. */
    public ?string $stock = null;

    public ?string $price = null;

    public ?array $result = null;

    public function mount(): void
    {
        $this->code = $this->code ?: (string) ($this->suppliers()->first()?->code ?? '');
    }

    public function suppliers()
    {
        return Supplier::orderBy('name')->get();
    }

    public function updatedCode(): void
    {
        $this->reset(['preview', 'problems', 'result', 'stock', 'price']);
    }

    public function updatedJson(): void
    {
        $this->reset(['preview', 'problems', 'result']);
    }

    public function look(): void
    {
        $this->validate([
            'code' => ['required', 'exists:suppliers,code'],
            'json' => ['required', 'string', 'min:20'],
        ]);

        $this->result = null;

        $read = (new AltaJson($this->supplier()))->read($this->json);

        $this->problems = $read['errors'];
        $this->preview = $read['seen'] ?: null;

        if (! $read['payload']) {
            return;
        }

        /* Filled in from the paste, and left for the operator to correct. */
        $this->stock ??= (string) $read['seen']['stock'];
        $this->price ??= (string) $read['seen']['price'];
    }

    public function save(ProductImporter $importer): void
    {
        $this->validate([
            'code' => ['required', 'exists:suppliers,code'],
            'json' => ['required', 'string', 'min:20'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $supplier = $this->supplier();
        $read = (new AltaJson($supplier))->read($this->json);

        $this->problems = $read['errors'];
        $this->preview = $read['seen'] ?: null;

        if (! $read['payload']) {
            $this->dispatch('toast', message: __('admin.json_nothing_to_save'));

            return;
        }

        $payload = $this->corrected($read['payload']);

        try {
            $product = $importer->import($supplier, $payload, insist: true);
        } catch (\Throwable $e) {
            Log::channel('import')->warning('pasted answer could not be saved', [
                'supplier' => $supplier->code,
                'external' => $payload->externalId,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            $this->problems[] = __('admin.json_failed', ['error' => $e->getMessage()]);

            return;
        }

        if (! $product) {
            $this->problems[] = __('admin.json_refused');

            return;
        }

        $this->result = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'images' => $product->images()->count(),
            'edit' => route('admin.products.edit', $product),
            'live' => $product->storefrontUrl(),
        ];

        $this->reset(['json', 'preview', 'stock', 'price']);

        $this->dispatch('toast', message: __('admin.json_saved', ['sku' => $product->sku]));
    }

    /*
     | The paste says what the shop front holds; what we hold and what we pay
     | is ours to say, so a corrected figure wins.
     */
    protected function corrected(ProductPayload $payload): ProductPayload
    {
        $stock = $this->stock === null || $this->stock === '' ? $payload->stock : (int) $this->stock;
        $price = $this->price === null || $this->price === '' ? $payload->costPrice : (float) $this->price;

        return new ProductPayload(
            externalId: $payload->externalId,
            sku: $payload->sku,
            costPrice: $price,
            oldCostPrice: $payload->oldCostPrice,
            stock: $stock,
            brandName: $payload->brandName,
            categoryName: $payload->categoryName,
            translations: $payload->translations,
            specs: $payload->specs,
            images: $payload->images,
            weight: $payload->weight,
        );
    }

    protected function supplier(): Supplier
    {
        return Supplier::where('code', $this->code)->firstOrFail();
    }

    public function render()
    {
        $supplier = $this->code ? Supplier::where('code', $this->code)->first() : null;

        return view('livewire.admin.import.json', [
            'suppliers' => $this->suppliers(),
            'supplier' => $supplier,

            /* What the markup would make of the price being shown. */
            'selling' => $supplier && $this->price !== null && $this->price !== ''
                ? $supplier->markup((float) $this->price)
                : null,
            'already' => $this->preview
                ? Product::withTrashed()
                    ->where('sku', ($supplier?->code ?? '').'-'.$this->preview['code'])
                    ->first()
                : null,
        ])->layout('layouts.admin', ['title' => __('admin.json_title')]);
    }
}
