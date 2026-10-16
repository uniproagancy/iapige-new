<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Support\Redact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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

        try {
            $payload = $manager->driver($supplier)->fetch($this->externalId);
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

        $importer->import($supplier, $payload);
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
