<?php

namespace App\Livewire\Admin\Import;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaJson;
use App\Services\Import\Drivers\Alta\AltaJsonAssets;
use App\Services\Import\Drivers\Alta\AltaPaste;
use App\Services\Import\ImageDownloader;
use App\Services\Import\JsonImportSource;
use App\Services\Import\JsonImportSourceException;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Support\Redact;
use Illuminate\Support\Facades\Gate;
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

    #[Url(as: 'supplier', except: '')]
    public string $code = '';

    public string $json = '';

    public $upload = null;

    #[Locked]
    public ?string $previewFingerprint = null;

    #[Locked]
    public ?string $sourceId = null;

    #[Locked]
    public ?string $sourceSha = null;

    #[Locked]
    public ?string $sourceName = null;

    #[Locked]
    public int $sourceSize = 0;

    #[Locked]
    public int $previewCursor = 0;

    #[Locked]
    public bool $previewComplete = false;

    #[Locked]
    public int $totalRecords = 0;

    #[Locked]
    public int $validRecords = 0;

    #[Locked]
    public int $errorCount = 0;

    #[Locked]
    public int $saveCursor = 0;

    #[Locked]
    public int $saveProcessed = 0;

    #[Locked]
    public int $savedCount = 0;

    #[Locked]
    public int $photoWarningCount = 0;

    #[Locked]
    public bool $saveStarted = false;

    #[Locked]
    public bool $saveComplete = false;

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
        $this->forgetSource();
        $this->forgetPreview();
    }

    /* The two corrections are only offered for a single answer: with a
       hundred of them there is nothing sensible one figure could mean. */
    public function alone(): bool
    {
        return $this->previewComplete && $this->totalRecords === 1 && count($this->preview) === 1;
    }

    public function updatedJson(): void
    {
        $this->forgetSource();
        $this->forgetPreview();
    }

    public function updatedUpload(): void
    {
        $this->forgetSource();
        if ($this->upload !== null) {
            $this->json = '';
        }

        $this->forgetPreview();
    }

    public function clearUpload(): void
    {
        Gate::authorize('admin');
        $this->reset('upload');
        $this->forgetSource();
        $this->forgetPreview();
    }

    protected function forgetPreview(): void
    {
        $this->reset(['preview', 'previewFingerprint', 'problems', 'result', 'stock', 'price',
            'previewCursor', 'previewComplete', 'totalRecords', 'validRecords', 'errorCount',
            'saveCursor', 'saveProcessed', 'savedCount', 'photoWarningCount', 'saveStarted', 'saveComplete']);
        $this->resetErrorBag();
    }

    protected function forgetSource(): void
    {
        $this->reset(['sourceId', 'sourceSha', 'sourceName', 'sourceSize']);
    }

    public function useSource(string $id): array
    {
        Gate::authorize('admin');
        $this->validate(['code' => ['required', 'exists:suppliers,code']]);
        $this->forgetPreview();
        $this->forgetSource();

        try {
            $info = app(JsonImportSource::class)->info((int) auth()->id(), $id, $this->code);
        } catch (JsonImportSourceException $e) {
            $this->sourceError($e);
        }

        if (($info['state'] ?? null) !== 'ready' || ! is_string($info['sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $info['sha256'])) {
            throw ValidationException::withMessages(['upload' => __('admin.json_source_incomplete')]);
        }

        $this->sourceId = $info['id'];
        $this->sourceSha = $info['sha256'];
        $this->sourceName = $info['name'];
        $this->sourceSize = $info['size'];
        $this->reset(['json', 'upload']);

        return ['id' => $this->sourceId, 'ready' => true];
    }

    public function look(): array
    {
        Gate::authorize('admin');
        $this->forgetPreview();

        if ($this->sourceId !== null) {
            return $this->previewNext();
        }

        [$source, $answers] = $this->source();

        $reader = new AltaJson($this->supplier());
        $assets = app(AltaJsonAssets::class);

        foreach ($answers as $i => $one) {
            $this->totalRecords++;
            $read = $this->readAnswer($reader, $assets, $one);

            foreach ($read['errors'] as $error) {
                $this->problem(__('admin.json_in_answer', ['n' => $i + 1]).' '.$error);
            }

            if ($read['seen'] && count($this->preview) < 50) {
                $this->preview[] = $read['seen'] + ['ok' => $read['payload'] !== null];
            }

            $this->validRecords += $read['payload'] !== null ? 1 : 0;
        }

        $this->previewComplete = true;

        /* Filled in from the paste, and left for the operator to correct. */
        if ($this->alone()) {
            $this->stock ??= (string) $this->preview[0]['stock'];
            $this->price ??= (string) $this->preview[0]['price'];
        }

        if ($this->validRecords > 0) {
            $this->previewFingerprint = $this->fingerprint($source);
        }

        return ['done' => true, 'processed' => $this->totalRecords];
    }

    public function save(ProductImporter $importer): array
    {
        Gate::authorize('admin');
        $this->validate([
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        if ($this->sourceId !== null) {
            $this->requireBulkPreview();
            $this->reset(['saveCursor', 'saveProcessed', 'savedCount', 'photoWarningCount', 'saveComplete', 'result', 'problems', 'errorCount']);
            $this->saveStarted = true;

            return $this->saveNext($importer);
        }

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
            $this->saveOne($importer, $supplier, $reader, $assets, $one, $alone, $i + 1);
        }

        if (! $this->result) {
            $this->dispatch('toast', message: __('admin.json_nothing_to_save'));

            return ['done' => true, 'processed' => count($answers)];
        }

        $this->reset(['json', 'upload', 'preview', 'previewFingerprint', 'stock', 'price']);

        $incomplete = $this->photoWarningCount;

        $this->dispatch('toast', type: $incomplete ? 'warning' : 'success', message: $incomplete
            ? __('admin.json_saved_with_photo_warnings', ['count' => $this->savedCount])
            : __('admin.json_saved_many', ['count' => $this->savedCount]));

        return ['done' => true, 'processed' => count($answers)];
    }

    public function previewNext(): array
    {
        Gate::authorize('admin');

        if ($this->previewComplete) {
            return ['done' => true, 'processed' => $this->totalRecords];
        }

        $batch = $this->sourceBatch($this->previewCursor, 10);
        $reader = new AltaJson($this->supplier());
        $assets = app(AltaJsonAssets::class);

        foreach ($batch['rows'] as $row) {
            $this->totalRecords++;
            $read = $this->readAnswer($reader, $assets, $row['json']);

            foreach ($read['errors'] as $error) {
                $this->problem(__('admin.json_in_answer', ['n' => $this->totalRecords]).' '.$error);
            }

            if ($read['seen'] && count($this->preview) < 50) {
                $this->preview[] = $read['seen'] + ['ok' => $read['payload'] !== null];
            }

            $this->validRecords += $read['payload'] !== null ? 1 : 0;
        }

        $this->previewCursor = $batch['next_cursor'];
        $this->previewComplete = $batch['eof'];

        if ($this->previewComplete) {
            if ($this->validRecords > 0) {
                $this->previewFingerprint = $this->bulkFingerprint();
            }

            if ($this->alone()) {
                $this->stock = (string) $this->preview[0]['stock'];
                $this->price = (string) $this->preview[0]['price'];
            }
        }

        return ['done' => $this->previewComplete, 'processed' => $this->totalRecords];
    }

    public function saveNext(ProductImporter $importer): array
    {
        Gate::authorize('admin');

        if ($this->saveComplete) {
            return ['done' => true, 'processed' => $this->saveProcessed];
        }

        $this->requireBulkPreview();

        if (! $this->saveStarted) {
            throw ValidationException::withMessages(['upload' => __('admin.json_preview_required')]);
        }

        $this->validate([
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $batch = $this->sourceBatch($this->saveCursor, 3);
        $supplier = $this->supplier();
        $reader = new AltaJson($supplier);
        $assets = app(AltaJsonAssets::class);

        foreach ($batch['rows'] as $row) {
            $this->saveOne($importer, $supplier, $reader, $assets, $row['json'], $this->alone(), $this->saveProcessed + 1);
            $this->saveProcessed++;
        }

        $this->saveCursor = $batch['next_cursor'];
        $this->saveComplete = $batch['eof'];

        if ($this->saveComplete) {
            $this->saveStarted = false;
            $this->previewFingerprint = null;
            $this->dispatch('toast', type: $this->errorCount ? 'warning' : 'success', message: $this->photoWarningCount
                ? __('admin.json_saved_with_photo_warnings', ['count' => $this->savedCount])
                : __('admin.json_saved_many', ['count' => $this->savedCount]));
        }

        return ['done' => $this->saveComplete, 'processed' => $this->saveProcessed];
    }

    protected function sourceBatch(int $cursor, int $limit): array
    {
        $this->assertBulkSource();

        try {
            $batch = app(JsonImportSource::class)->readBatch((int) auth()->id(), $this->sourceId, $cursor, $limit, $this->code);

            if (! hash_equals($this->sourceSha, $batch['sha256'])) {
                throw ValidationException::withMessages(['upload' => __('admin.json_source_changed')]);
            }

            return $batch;
        } catch (JsonImportSourceException $e) {
            $this->sourceError($e);
        } catch (ValidationException $e) {
            $this->previewFingerprint = null;
            $this->previewComplete = false;
            $this->saveStarted = false;

            throw $e;
        }
    }

    protected function assertBulkSource(): void
    {
        if ($this->sourceId === null || $this->sourceSha === null) {
            throw ValidationException::withMessages(['upload' => __('admin.json_source_not_found')]);
        }

        try {
            $info = app(JsonImportSource::class)->info((int) auth()->id(), $this->sourceId, $this->code);

            if (! hash_equals($this->sourceSha, (string) $info['sha256'])) {
                $this->previewFingerprint = null;
                $this->previewComplete = false;
                $this->saveStarted = false;

                throw ValidationException::withMessages(['upload' => __('admin.json_source_changed')]);
            }
        } catch (JsonImportSourceException $e) {
            $this->sourceError($e);
        }
    }

    protected function requireBulkPreview(): void
    {
        $this->assertBulkSource();

        if (! $this->previewComplete || $this->previewFingerprint === null
            || ! hash_equals($this->previewFingerprint, $this->bulkFingerprint())) {
            throw ValidationException::withMessages(['upload' => __('admin.json_preview_required')]);
        }
    }

    protected function bulkFingerprint(): string
    {
        return hash('sha256', $this->code."\0".$this->sourceId."\0".$this->sourceSha);
    }

    protected function sourceError(JsonImportSourceException $error): never
    {
        $this->previewFingerprint = null;
        $this->previewComplete = false;
        $this->saveStarted = false;

        throw ValidationException::withMessages(['upload' => __('admin.'.$error->reason)]);
    }

    protected function problem(string $message): void
    {
        $this->errorCount++;
        $this->problems[] = $message;
        $this->problems = array_slice($this->problems, -20);
    }

    protected function saveOne(ProductImporter $importer, Supplier $supplier, AltaJson $reader, AltaJsonAssets $assets, string $one, bool $alone, int $number): void
    {
        $read = $this->readAnswer($reader, $assets, $one);

        if (! $read['payload']) {
            foreach ($read['errors'] as $error) {
                $this->problem(__('admin.json_in_answer', ['n' => $number]).' '.$error);
            }

            return;
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

            $this->problem(__('admin.json_in_answer', ['n' => $number]).' '
                .__('admin.json_failed', ['error' => Redact::secrets($e->getMessage())]));

            return;
        }

        if (! $product) {
            $this->problem(__('admin.json_in_answer', ['n' => $number]).' '.__('admin.json_refused'));

            return;
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
            $this->photoWarningCount++;
            $this->problem(__('admin.json_in_answer', ['n' => $number]).' '
                .__('admin.json_photos_incomplete', ['saved' => $imageCount, 'wanted' => $wanted]));
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
        $this->result = array_slice($this->result, -50);
        $this->savedCount++;
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

        return [$source, $answers];
    }

    protected function sourceField(): string
    {
        return $this->upload !== null || $this->sourceId !== null ? 'upload' : 'json';
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
            'photoWarnings' => $this->photoWarningCount,
            'uploadErrors' => $this->uploadErrors(),

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

    protected function uploadErrors(): array
    {
        $messages = [];

        foreach (['json_source_invalid', 'json_source_not_found', 'json_source_forbidden', 'json_source_expired',
            'json_source_changed', 'json_source_incomplete', 'json_source_state', 'json_source_unavailable', 'json_chunk_offset',
            'json_chunk_mismatch', 'json_chunk_invalid', 'json_source_no_space', 'json_record_too_large',
            'json_source_invalid_json', 'json_cursor_invalid'] as $reason) {
            $messages[$reason] = __('admin.'.$reason);
        }

        foreach (['csrf_missing', 'session_expired', 'upload_forbidden'] as $reason) {
            $messages[$reason] = __('admin.json_upload_access');
        }

        foreach (['upload_invalid_file', 'upload_empty_file', 'upload_invalid_supplier', 'upload_validation_failed'] as $reason) {
            $messages[$reason] = __('admin.json_upload_invalid');
        }

        $messages['upload_cancelled'] = __('admin.json_upload_cancelled');
        $messages['upload_disk_full'] = __('admin.json_source_no_space');
        $messages['upload_too_large'] = __('admin.json_upload_chunk_limit');

        return $messages;
    }
}
