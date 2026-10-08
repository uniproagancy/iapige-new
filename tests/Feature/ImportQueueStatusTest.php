<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Telling a stale job from a broken one.
 *
 * Laravel writes maxTries and retryUntil into the payload when a job is
 * queued, so a job dispatched before those were corrected still obeys the old
 * rules — and queue:retry re-pushes the same payload, which is why the same
 * failure kept coming back after each fix. The class is not the authority
 * here; the payload is, and this is what reads it.
 */
class ImportQueueStatusTest extends TestCase
{
    use RefreshDatabase;

    /** The case that cost us three rounds of guessing. */
    public function test_a_job_queued_before_the_fix_is_called_out(): void
    {
        $this->queueJob(['maxTries' => 3]);

        $this->artisan('import:queue')
            ->expectsOutputToContain('retryUntil  none')
            ->expectsOutputToContain('clear the queue and run the import again')
            ->assertSuccessful();
    }

    /** And one queued after it is not. */
    public function test_a_job_carrying_a_deadline_is_not_flagged(): void
    {
        $this->queueJob(['maxTries' => null, 'retryUntil' => now()->addDay()->getTimestamp()]);

        $this->artisan('import:queue')
            ->doesntExpectOutputToContain('clear the queue')
            ->assertSuccessful();
    }

    public function test_waiting_jobs_are_counted_per_queue(): void
    {
        $this->queueJob([], 'import:zoommer');
        $this->queueJob([], 'import:zoommer');
        $this->queueJob([], 'import:elite');

        $this->artisan('import:queue')
            ->expectsOutputToContain('import:zoommer')
            ->expectsOutputToContain('import:elite')
            ->assertSuccessful();
    }

    /** A supplier code narrows it to that one queue. */
    public function test_a_supplier_narrows_the_report(): void
    {
        $this->queueJob([], 'import:zoommer');
        $this->queueJob([], 'import:elite');

        $this->artisan('import:queue', ['supplier' => 'zoommer'])
            ->doesntExpectOutputToContain('import:elite')
            ->assertSuccessful();
    }

    /**
     * Failures are grouped, because a thousand rows of one message is not a
     * thousand problems.
     */
    public function test_failures_are_grouped_by_their_message(): void
    {
        $this->failJob('Illuminate\Queue\MaxAttemptsExceededException: too many times');
        $this->failJob('Illuminate\Queue\MaxAttemptsExceededException: too many times');
        $this->failJob('PDOException: Data too long for column');

        $this->artisan('import:queue')
            ->expectsOutputToContain('MaxAttemptsExceededException')
            ->expectsOutputToContain('Data too long')
            ->assertSuccessful();
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->artisan('import:queue')
            ->expectsOutputToContain('nothing waiting')
            ->expectsOutputToContain('no failed jobs')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ helpers */

    protected function queueJob(array $payload, string $queue = 'import:zoommer'): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode($payload + ['displayName' => 'App\Jobs\ImportProductJob']),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }

    protected function failJob(string $exception): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'import:zoommer',
            'payload' => json_encode(['displayName' => 'App\Jobs\ImportProductJob']),
            'exception' => $exception."\nStack trace:\n#0 somewhere",
            'failed_at' => now(),
        ]);
    }
}
