<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaImageCache;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Support\Redact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ImportProductJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    public int $timeout;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours((int) config('shop.import_deadline_hours', 24));
    }

    public function __construct(
        public int $supplierId,
        public string $externalId,
    ) {
        $this->timeout = (int) config('shop.import_timeout', 300);
    }

    public function middleware(): array
    {
        return [new RateLimited('import')];
    }

    public function handle(ImportManager $manager, ProductImporter $importer): void
    {
        $supplier = Supplier::find($this->supplierId);

        if (! $supplier?->is_active) {
            return;
        }

        $sourcePath = null;
        $sourceHash = null;

        if ($supplier->driver === AltaDriver::class && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $this->externalId)) {
            $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);
            $directory = $config['retail_directory'] ?? storage_path('app/private/alta-retail');

            if ($directory) {
                $sourcePath = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$this->externalId.'.json';
                $sourceHash = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null;
            }
        }

        try {
            $payload = $manager->driver($supplier)->fetch($this->externalId);
        } catch (AltaUnavailable $e) {
            // A blocked supplier request is a failed attempt, not a successful import.
            throw $e;
        } catch (\Throwable $e) {

            Log::channel('import')->warning('import fetch failed', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            return;
        }

        if (! $payload) {

            Log::channel('import')->debug('nothing to import', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
            ]);

            return;
        }

        $product = $importer->import($supplier, $payload);

        if ($product && $sourceHash && is_file($sourcePath) && hash_file('sha256', $sourcePath) === $sourceHash) {
            if (! AltaImageCache::verifyProduct($product, $payload->images, dirname($sourcePath), prune: true)) {
                throw new AltaUnavailable('Alta image storage is incomplete. The import will be retried.');
            }

            Cache::put('alta-browser-complete:'.$supplier->id.':'.$this->externalId, [
                'hash' => $sourceHash,
                'completed_at' => now()->toIso8601String(),
                'product_id' => $product->id,
                'images_complete' => true,
            ], now()->addDays(2));
        }
    }

    public function failed(?\Throwable $e): void
    {
        $payload = $this->job?->payload() ?? [];
        $until = $payload['retryUntil'] ?? null;

        Log::channel('import')->error('import job gave up', [
            'supplier' => Supplier::find($this->supplierId)?->code,
            'external' => $this->externalId,
            'attempts' => $this->job ? $this->attempts() : null,
            'queued_with' => [
                'maxTries' => $payload['maxTries'] ?? null,
                'retryUntil' => $until ? Carbon::createFromTimestamp($until)->toDateTimeString() : null,
            ],
            'error' => $e ? Redact::secrets($e->getMessage()) : null,
        ]);
    }
}
