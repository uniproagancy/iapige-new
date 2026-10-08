<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductImporter;
use App\Support\Redact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class ImportProductJob implements ShouldQueue
{
    use Queueable;

    /**
     * A deadline, not a count of attempts.
     *
     * $tries counts every attempt, and most of this job's attempts end without
     * anything going wrong: the rate limiter releases it back to the queue to
     * keep the source's pace, and a release is an attempt. A busy run spent
     * all three on being politely postponed and the job died of "attempted too
     * many times" having never once run — which is why the failure arrived with
     * no underlying exception attached to it.
     *
     * Laravel prefers retryUntil over tries when both exist, so a job may be
     * released as often as the pacing needs within the half hour, while
     * $maxExceptions still stops anything that genuinely keeps throwing.
     */
    public int $maxExceptions = 3;

    public int $backoff = 30;

    /**
     * Shorter than the queue's retry_after of 90 seconds, on purpose: a job
     * still running when that elapses is handed to a second worker as well,
     * and two workers importing one product race each other over its rows.
     */
    public int $timeout = 75;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function __construct(
        public int $supplierId,
        public string $externalId,
    ) {}

    /** Keeps us within whatever pace the source tolerates. */
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
            /*
             * On the import channel with everything else, and with the
             * credentials taken out: several suppliers carry a token in the
             * query string, so a client error quotes the whole URL and a plain
             * timeout was enough to write a live token into the log file.
             */
            Log::channel('import')->warning('import fetch failed', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            return;   // a single bad product must not stop the run
        }

        if (! $payload) {
            /*
             * Worth a line, because nothing else records it.
             *
             * The drivers that walk an id range ask for plenty of ids that do
             * not exist, so this is debug rather than info — but a run that
             * saves nothing at all used to leave no trace whatsoever, and
             * raising the channel to debug is now enough to see why.
             */
            Log::channel('import')->debug('nothing to import', [
                'supplier' => $supplier->code,
                'external' => $this->externalId,
            ]);

            return;
        }

        $importer->import($supplier, $payload);
    }
}
