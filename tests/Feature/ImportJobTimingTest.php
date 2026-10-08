<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Services\Import\ImageDownloader;
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
        $budget = (new \ReflectionClassConstant(ImageDownloader::class, 'BUDGET'))->getValue();
        $timeout = (new ImportProductJob(1, 'X-1'))->timeout;

        $this->assertLessThan($timeout, $budget);

        // the API calls and the database work share the same job
        $this->assertLessThanOrEqual($timeout * 0.7, $budget);
    }

    /** The pacing the whole arrangement exists to respect. */
    public function test_the_job_is_rate_limited(): void
    {
        $this->assertInstanceOf(RateLimited::class, (new ImportProductJob(1, 'X-1'))->middleware()[0]);
    }
}
