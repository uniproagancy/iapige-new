<?php

namespace App\Livewire\Admin\Import;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaJson;
use App\Services\Import\Drivers\Alta\AltaJsonAssets;
use App\Services\Import\Drivers\Alta\AltaPaste;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    public const MAX_UPLOAD_KB = 10240;

    public const MAX_ANSWERS = 100;

    #[Url(as: 'supplier', except: '')]
    public string $code = '';

    public string $json = '';

    public $upload = null;

    #[Locked]
    public ?string $previewFingerprint = null;

    /* What the paste would become, shown before anything is written.
       One row per answer in it. */
    public array $preview = [];

    /* Not $errors: that name belongs to Blade's own error bag, and a
       property of the same name hides it from every @error in the view. */
    public array $problems = [];

    /* Both are the operator's to change before saving. */
    public ?string $stock = null;

    public ?string $price = null;

    public array $result = [];

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
        $this->forgetPreview();
    }

    /* The two corrections are only offered for a single answer: with a
       hundred of them there is nothing sensible one figure could mean. */
    public function alone(): bool
    {
        return count($this->preview) === 1;
    }

    public function updatedJson(): void
    {
        $this->forgetPreview();
    }

    public function updatedUpload(): void
    {
        if ($this->upload !== null) {
            $this->json = '';
        }

        $this->forgetPreview();
    }

    public function clearUpload(): void
    {
        $this->reset('upload');
        $this->forgetPreview();
    }

    protected function forgetPreview(): void
    {
        $this->reset(['preview', 'previewFingerprint', 'problems', 'result', 'stock', 'price']);
        $this->resetErrorBag();
    }

    public function look(): void
    {
        $this->forgetPreview();
        [$source, $answers] = $this->source();

        $reader = new AltaJson($this->supplier());
        $assets = app(AltaJsonAssets::class);

        foreach ($answers as $i => $one) {
            $read = $this->readAnswer($reader, $assets, $one);

            foreach ($read['errors'] as $error) {
                $this->problems[] = __('admin.json_in_answer', ['n' => $i + 1]).' '.$error;
            }

            if ($read['seen']) {
                $this->preview[] = $read['seen'] + ['ok' => $read['payload'] !== null];
            }
        }

        /* Filled in from the paste, and left for the operator to correct. */
        if ($this->alone()) {
            $this->stock ??= (string) $this->preview[0]['stock'];
            $this->price ??= (string) $this->preview[0]['price'];
        }

        if (array_filter($this->preview, fn (array $row) => $row['ok'])) {
            $this->previewFingerprint = $this->fingerprint($source);
        }
    }

    public function save(ProductImporter $importer): void
    {
        $this->validate([
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        [$source, $answers] = $this->source();

        if ($this->previewFingerprint === null || ! hash_equals($this->previewFingerprint, $this->fingerprint($source))) {
            $this->forgetPreview();

            throw ValidationException::withMessages([$this->sourceField() => __('admin.json_preview_required')]);
        }

        $supplier = $this->supplier();
        $reader = new AltaJson($supplier);
        $assets = app(AltaJsonAssets::class);
        $this->problems = [];
        $this->result = [];

        $alone = count($answers) === 1;

        foreach ($answers as $i => $one) {
            $read = $this->readAnswer($reader, $assets, $one);

            if (! $read['payload']) {
                foreach ($read['errors'] as $error) {
                    $this->problems[] = __('admin.json_in_answer', ['n' => $i + 1]).' '.$error;
                }

                continue;
            }

            /* A lone answer can have its cost and stock corrected by hand;
               among many there is no one figure to correct them with. */
            $payload = $alone ? $this->corrected($read['payload']) : $read['payload'];

            try {
                if ($read['assets'] !== null) {
                    $assets->store($supplier, $read['assets']);
                }

                $product = $importer->import($supplier, $payload, insist: true);
            } catch (\Throwable $e) {
                Log::channel('import')->warning('pasted answer could not be saved', [
                    'supplier' => $supplier->code,
                    'external' => $payload->externalId,
                    'error' => Redact::secrets($e->getMessage()),
                ]);

                $this->problems[] = __('admin.json_in_answer', ['n' => $i + 1]).' '
                    .__('admin.json_failed', ['error' => $e->getMessage()]);

                continue;
            }

            if (! $product) {
                $this->problems[] = __('admin.json_in_answer', ['n' => $i + 1]).' '.__('admin.json_refused');

                continue;
            }

            $imageCount = $product->images()->count();
            $wanted = min(count($payload->images), ImageDownloader::MAX_IMAGES);
            $photosComplete = $imageCount >= $wanted;

            if ($read['assets'] !== null) {
                try {
                    $photosComplete = $photosComplete && $assets->verify($product, $supplier, $payload->images);
                } catch (\Throwable) {
                    $photosComplete = false;
                }
            }

            if (! $photosComplete) {
                $this->problems[] = __('admin.json_in_answer', ['n' => $i + 1]).' '
                    .__('admin.json_photos_incomplete', ['saved' => $imageCount, 'wanted' => $wanted]);
            }

            $this->result[] = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
                'images' => $imageCount,
                'photos_complete' => $photosComplete,
                'edit' => route('admin.products.edit', $product),
                'live' => $product->storefrontUrl(),
            ];
        }

        if (! $this->result) {
            $this->dispatch('toast', message: __('admin.json_nothing_to_save'));

            return;
        }

        $this->reset(['json', 'upload', 'preview', 'previewFingerprint', 'stock', 'price']);

        $incomplete = count(array_filter($this->result, fn (array $row) => ! $row['photos_complete']));

        $this->dispatch('toast', type: $incomplete ? 'warning' : 'success', message: $incomplete
            ? __('admin.json_saved_with_photo_warnings', ['count' => count($this->result)])
            : __('admin.json_saved_many', ['count' => count($this->result)]));
    }

    protected function readAnswer(AltaJson $reader, AltaJsonAssets $assets, string $one): array
    {
        $read = $reader->read($one);
        $read['assets'] = null;

        try {
            $read['assets'] = $assets->validate($one, $read['payload']?->images ?? []);
        } catch (ValidationException) {
            $read['errors'][] = __('admin.alta_invalid_export');
            $read['payload'] = null;
        }

        return $read;
    }

    /* File contents stay on the server. Re-read them for both passes so a
       replaced or expired temporary upload cannot save an older preview. */
    protected function source(): array
    {
        $this->validate([
            'code' => ['required', 'exists:suppliers,code'],
            'json' => ['nullable', 'string', 'max:'.(self::MAX_UPLOAD_KB * 1024)],
        ]);

        if ($this->upload !== null) {
            try {
                $this->validate([
                    'upload' => ['required', 'file', 'mimes:json,txt', 'mimetypes:application/json,text/plain', 'extensions:json', 'max:'.self::MAX_UPLOAD_KB],
                ]);
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable) {
                throw ValidationException::withMessages(['upload' => __('admin.json_file_unreadable')]);
            }
        }

        if ($this->upload !== null && trim($this->json) !== '') {
            throw ValidationException::withMessages(['upload' => __('admin.json_one_source')]);
        }

        $source = $this->json;

        if ($this->upload !== null) {
            $stream = null;

            try {
                $stream = $this->upload->readStream();
                $source = is_resource($stream) ? stream_get_contents($stream, self::MAX_UPLOAD_KB * 1024 + 1) : false;
            } catch (\Throwable) {
                $source = false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        if (! is_string($source) || strlen($source) > self::MAX_UPLOAD_KB * 1024) {
            throw ValidationException::withMessages([$this->sourceField() => __('admin.json_file_unreadable')]);
        }

        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);

        if (strlen(trim($source)) < 20) {
            throw ValidationException::withMessages([$this->sourceField() => __('admin.json_not_json')]);
        }

        if ($this->upload !== null) {
            json_decode($source);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages(['upload' => __('admin.json_not_json')]);
            }
        }

        $answers = AltaPaste::split($source);

        if (count($answers) > self::MAX_ANSWERS) {
            throw ValidationException::withMessages([$this->sourceField() => __('admin.json_batch_limit', ['count' => self::MAX_ANSWERS])]);
        }

        return [$source, $answers];
    }

    protected function sourceField(): string
    {
        return $this->upload !== null ? 'upload' : 'json';
    }

    protected function fingerprint(string $source): string
    {
        return hash('sha256', $this->code."\0".($this->upload !== null ? 'file' : 'paste')."\0".$source);
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
            'photoWarnings' => count(array_filter($this->result, fn (array $row) => ! ($row['photos_complete'] ?? true))),

            /* What the markup would make of the price being shown. */
            'selling' => $supplier && $this->alone() && $this->price !== null && $this->price !== ''
                ? $supplier->markup((float) $this->price)
                : null,

            /* Which of the pasted answers the shop already holds. */
            'already' => $supplier && $this->preview
                ? Product::withTrashed()
                    ->whereIn('sku', array_map(
                        fn (array $row) => $supplier->code.'-'.$row['code'],
                        $this->preview,
                    ))
                    ->pluck('sku', 'sku')
                : collect(),
        ])->layout('layouts.admin', ['title' => __('admin.json_title')]);
    }
}
