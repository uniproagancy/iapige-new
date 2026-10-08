<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use App\Services\Import\ImportManager;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Discarding a supplier's queued work and starting it again.
 *
 * A job carries the retry rules it was given when it was queued, not the ones
 * its class declares today, and queue:retry re-sends the same payload — so a
 * correction to those rules only reaches jobs dispatched after it. The only
 * way through is to discard and re-dispatch, and getting that wrong is what
 * made three separate fixes look like no fix at all.
 */
class RestartImportTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    public function test_waiting_jobs_are_discarded_and_queued_again(): void
    {
        $this->queued('import:zoommer', 3);

        $this->artisan('import:restart', ['supplier' => 'zoommer', '--force' => true])
            ->expectsOutputToContain('3 waiting')
            ->assertSuccessful();

        // the three stale payloads are gone and two fresh ones took their place
        $this->assertSame(2, DB::table('jobs')->where('queue', 'import:zoommer')->count());
    }

    public function test_failed_jobs_for_that_queue_are_cleared(): void
    {
        $this->failed('import:zoommer');

        $this->artisan('import:restart', ['supplier' => 'zoommer', '--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('failed_jobs')->where('queue', 'import:zoommer')->count());
    }

    /** One supplier's clean-up must not touch another's work. */
    public function test_another_supplier_is_left_alone(): void
    {
        $this->queued('import:elite', 4);
        $this->failed('import:elite');

        $this->artisan('import:restart', ['supplier' => 'zoommer', '--force' => true])->assertSuccessful();

        $this->assertSame(4, DB::table('jobs')->where('queue', 'import:elite')->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('queue', 'import:elite')->count());
    }

    /** The new jobs carry a deadline, which is the whole point of restarting. */
    public function test_the_new_jobs_carry_todays_rules(): void
    {
        $this->artisan('import:restart', ['supplier' => 'zoommer', '--force' => true])->assertSuccessful();

        $payload = json_decode(DB::table('jobs')->where('queue', 'import:zoommer')->first()->payload, true);

        $this->assertNotNull($payload['retryUntil'], 'a job queued today must carry the deadline');
        $this->assertGreaterThan(now()->addHours(12)->getTimestamp(), $payload['retryUntil']);
    }

    public function test_an_unknown_supplier_is_refused(): void
    {
        $this->artisan('import:restart', ['supplier' => 'nobody', '--force' => true])
            ->expectsOutputToContain('No supplier')
            ->assertFailed();
    }

    /* ------------------------------------------------------------------ the job */

    /** The anonymous failure, given a name. */
    public function test_a_job_that_gives_up_says_which_product_it_was(): void
    {
        Log::shouldReceive('channel')->with('import')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'import job gave up'
                && $context['supplier'] === 'zoommer'
                && $context['external'] === '54500';
        });

        (new ImportProductJob($this->supplier->id, '54500'))->failed(new \RuntimeException('boom'));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'is_active' => true, 'priority' => 1,
            'driver' => TwoProductDriver::class,
            'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $this->app->instance(ImportManager::class, new ImportManager);

        /*
         * The suite runs the queue synchronously, which is right for every
         * other test and useless here: this command is about what a payload
         * written to the jobs table carries.
         */
        config(['queue.default' => 'database']);
    }

    protected function queued(string $queue, int $count): void
    {
        foreach (range(1, $count) as $i) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                // the shape that caused this: limits, but no deadline
                'payload' => json_encode(['maxTries' => 3, 'displayName' => ImportProductJob::class]),
                'attempts' => 3,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]);
        }
    }

    protected function failed(string $queue): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => ImportProductJob::class]),
            'exception' => 'MaxAttemptsExceededException',
            'failed_at' => now(),
        ]);
    }
}

/** Two ids, so the restart has something to queue without touching the network. */
class TwoProductDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return ['A-1', 'A-2'];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}
