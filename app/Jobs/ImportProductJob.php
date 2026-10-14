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
     * released as often as the pacing needs, while $maxExceptions still stops
     * anything that genuinely keeps throwing.
     *
     * The deadline has to outlast the whole run rather than one job. At sixty
     * products a minute the last of ten thousand is postponed for most of an
     * afternoon before its turn comes, and a deadline measured in minutes
     * killed it on the way — the same empty failure as before, just later.
     */
    public int $maxExceptions = 3;

    public int $backoff = 30;

    /**
     * Read at dispatch, which is when Laravel copies it into the payload.
     *
     * The default was the worker's own sixty seconds, and a product is two
     * API calls plus its photographs — comfortably more than that. Being
     * killed leaves no exception behind, only a spent attempt, which is why
     * the run failed with nothing in the log to explain it.
     */
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

    /**
     * Say which product gave up, and under which rules.
     *
     * "Attempted too many times" names neither the supplier nor the product,
     * and it is thrown before handle() runs — so none of the logging in there
     * ever fires and the line arrives anonymous. Worse, the rules that decided
     * it are the ones written into the payload when the job was queued, not
     * the ones this class declares today, so a job queued before a fix still
     * dies by the old limits and looks exactly like the fix not working.
     *
     * Both are printed here. A line showing maxTries with no retryUntil is a
     * job from before the deadline was introduced: clear the queue and run the
     * import again, because retrying re-sends the very same payload.
     */
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
