<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Tests\TestCase;

/**
 * The numbers that have to stay in step.
 *
 * The import died of "attempted too many times" with no exception under it,
 * which is what a job looks like when it is killed rather than when it fails:
 * the attempt is spent, nothing is written down. Two separate things spent
 * them — the rate limiter releasing the job back to the queue, and the worker
 * cutting off a product whose photographs took longer than the job was
 * allowed to live.
 *
 * Each of these pins one of the relationships involved. None of them is
 * obvious from reading any single file, which is exactly why they drifted.
 */
class ImportJobTimingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A deadline instead of a count.
     *
     * The rate limiter releases this job to keep the source's pace, and every
     * release counts against $tries — so a busy run used all three being
     * postponed and never ran at all.
     */
    public function test_the_job_retries_against_a_clock(): void
    {
        $job = new ImportProductJob(1, 'X-1');

        $this->assertTrue(method_exists($job, 'retryUntil'));
        $this->assertFalse(property_exists($job, 'tries'), 'tries would take precedence away from the deadline');
        $this->assertGreaterThan(now()->addMinutes(5), $job->retryUntil());
    }

    /**
     * The deadline has to cover the run, not the job.
     *
     * Every product behind the rate limiter is being postponed until its turn
     * comes, and at sixty a minute the last of ten thousand waits most of an
     * afternoon. A deadline shorter than that kills the tail of every large
     * catalogue — with no exception recorded, because being postponed is not
     * an error.
     */
    public function test_the_deadline_outlasts_a_full_catalogue(): void
    {
        $minutes = 10000 / max(1, (int) config('shop.import_rate'));

        $this->assertGreaterThan(
            now()->addMinutes($minutes),
            (new ImportProductJob(1, 'X-1'))->retryUntil(),
        );
    }

    /** Several suppliers at once never share a queue or a limit. */
    public function test_each_supplier_is_paced_on_its_own(): void
    {
        $limiter = app(RateLimiter::class);

        $this->assertNotNull($limiter->limiter('import'));

        $first = $limiter->limiter('import')(new ImportProductJob(1, 'X-1'));
        $second = $limiter->limiter('import')(new ImportProductJob(2, 'X-1'));

        $this->assertNotSame($first->key, $second->key);
    }

    /* ------------------------------------------------------------------ the pace */

    /**
     * A supplier that needs a gentler pace says so itself.
     *
     * The limit was counted per supplier already, but the number came from
     * config and so was the same everywhere: four requests a second. A small
     * shop starts throttling at that, and the shape is unmistakable — fast for
     * the first minute, then twenty seconds a request for the rest of the run.
     *
     * Being throttled costs far more than going slowly: four hundred products
     * at twenty seconds is over two hours, the same four hundred at one every
     * two seconds is thirteen minutes.
     */
    public function test_a_supplier_can_ask_for_a_gentler_pace(): void
    {
        $supplier = Supplier::create([
            'code' => 'slow', 'name' => 'Slow', 'driver' => 'X',
            'is_active' => true, 'priority' => 1, 'config' => ['rate' => 30],
        ]);

        $limit = app(RateLimiter::class)->limiter('import')(new ImportProductJob($supplier->id, 'X-1'));

        $this->assertSame(30, $limit->maxAttempts);
    }

    public function test_a_supplier_without_one_keeps_the_configured_pace(): void
    {
        config(['shop.import_rate' => 240]);

        $supplier = Supplier::create([
            'code' => 'normal', 'name' => 'Normal', 'driver' => 'X',
            'is_active' => true, 'priority' => 1,
        ]);

        $limit = app(RateLimiter::class)->limiter('import')(new ImportProductJob($supplier->id, 'X-1'));

        $this->assertSame(240, $limit->maxAttempts);
    }

    /**
     * A limiter must never be the thing that throws.
     *
     * It is asked for on every job, including where the suppliers table is
     * not reachable, and a rate is not worth failing a run over.
     */
    public function test_an_unknown_supplier_falls_back_rather_than_failing(): void
    {
        config(['shop.import_rate' => 120]);

        $limit = app(RateLimiter::class)->limiter('import')(new ImportProductJob(9999, 'X-1'));

        $this->assertSame(120, $limit->maxAttempts);
    }

    /** Something still has to stop a job that genuinely keeps throwing. */
    public function test_real_failures_are_still_capped(): void
    {
        $this->assertSame(3, (new ImportProductJob(1, 'X-1'))->maxExceptions);
    }

    /**
     * The job must give up before the queue hands it to a second worker.
     *
     * Past retry_after the queue considers the job abandoned and releases it
     * while the first worker is still inside it, so two workers import one
     * product and race over its rows.
     */
    public function test_the_job_times_out_before_the_queue_reclaims_it(): void
    {
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            (new ImportProductJob(1, 'X-1'))->timeout,
        );
    }

    /**
     * And the photographs must fit inside the job, with room to spare.
     *
     * Eight images at twenty seconds each is far more than the job has. The
     * budget stops early instead, and the next run picks up what is missing —
     * a file already on disk costs no request.
     */
    public function test_the_image_budget_fits_inside_the_job(): void
    {
        $budget = (int) config('shop.import_image_budget');
        $timeout = (new ImportProductJob(1, 'X-1'))->timeout;

        $this->assertLessThan($timeout, $budget);

        // the API calls and the database work share the same job
        $this->assertLessThanOrEqual($timeout * 0.7, $budget);
    }

    /**
     * The worst a single product can cost, against what it is allowed.
     *
     * Every driver now asks for the same per-request timeout, so the worst a
     * product can cost is that many requests plus the picture budget. It came
     * to more than the job was given, and the slowest products were therefore
     * killed every time round — silently, because a kill records no
     * exception, only a spent attempt.
     */
    public function test_the_slowest_product_fits_in_the_job(): void
    {
        $worst = (int) config('shop.import_request_timeout') * (int) config('shop.import_requests_per_product')
            + (int) config('shop.import_image_budget');

        $this->assertLessThanOrEqual(
            (new ImportProductJob(1, 'X-1'))->timeout,
            $worst,
            "a slow product needs {$worst}s and the job allows less",
        );
    }

    /** The pacing the whole arrangement exists to respect. */
    public function test_the_job_is_rate_limited(): void
    {
        $this->assertInstanceOf(RateLimited::class, (new ImportProductJob(1, 'X-1'))->middleware()[0]);
    }
}
