<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Livewire\Admin\Import\Run;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Models\User;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Starting an import without a terminal.
 *
 * Queueing a run has only ever been an artisan command, so the nightly work
 * needed somebody at a shell — and the person who decides a supplier needs
 * refreshing is the one looking at its products, not the one with a terminal
 * open.
 *
 * This queues the jobs. It does not process them, and the screen says so,
 * because a queue that only grows is the thing people spend an afternoon not
 * noticing.
 */
class ImportRunScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    /* ------------------------------------------------------------------ queueing */

    public function test_picking_a_supplier_queues_its_products(): void
    {
        Queue::fake();

        $this->rows('A-1', 'A-2');

        $this->screen()->set('picked', 'demo')->call('run');

        Queue::assertPushed(ImportProductJob::class, 2);
    }

    /** The row's own button names the supplier, so no picking is needed. */
    public function test_a_row_can_be_run_directly(): void
    {
        Queue::fake();

        $this->rows('A-1');

        $this->screen()->call('run', 'demo');

        Queue::assertPushed(ImportProductJob::class, 1);
    }

    public function test_the_jobs_go_on_the_suppliers_own_queue(): void
    {
        Queue::fake();

        $this->rows('A-1');

        $this->screen()->call('run', 'demo');

        Queue::assertPushed(fn (ImportProductJob $job) => $job->queue === 'import:demo');
    }

    public function test_the_last_run_is_recorded(): void
    {
        Queue::fake();

        $this->rows('A-1');

        $this->screen()->call('run', 'demo');

        $this->assertNotNull($this->supplier->fresh()->last_run_at);
    }

    /* ------------------------------------------------------------------ nothing to do */

    public function test_choosing_nothing_is_refused(): void
    {
        Queue::fake();

        $this->screen()->set('picked', '')->call('run');

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_supplier_is_refused(): void
    {
        Queue::fake();

        $this->screen()->call('run', 'nobody');

        Queue::assertNothingPushed();
    }

    /** A supplier with no rows queues nothing, and says so rather than failing. */
    public function test_a_supplier_with_nothing_to_import_queues_nothing(): void
    {
        Queue::fake();

        $this->screen()->call('run', 'demo');

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------ failed jobs */

    /**
     * A failed job carries the rules it was queued with.
     *
     * Retrying one from before a fix fails again by the old rules and looks
     * exactly like the fix not working, so clearing them is the cure.
     */
    public function test_failed_jobs_can_be_cleared(): void
    {
        $this->failed('import:demo');
        $this->failed('import:other');

        $this->screen()->call('clearFailed', 'demo');

        $this->assertSame(0, DB::table('failed_jobs')->where('queue', 'import:demo')->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('queue', 'import:other')->count());
    }

    /* ------------------------------------------------------------------ what it shows */

    public function test_every_supplier_is_listed(): void
    {
        Supplier::create([
            'code' => 'other', 'name' => 'Other', 'driver' => DemoDriver::class,
            'is_active' => true, 'priority' => 9,
        ]);

        $this->screen()->assertSee('Demo')->assertSee('Other');
    }

    public function test_it_counts_what_is_waiting(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'import:demo', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->screen()->assertSee('php artisan queue:work');
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'demo',
            'name' => 'Demo',
            'driver' => DemoDriver::class,
            'is_active' => true,
            'priority' => 1,
        ]);
    }

    protected function screen()
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Run::class);
    }

    protected function rows(string ...$ids): void
    {
        foreach ($ids as $id) {
            SupplierStock::create([
                'supplier_id' => $this->supplier->id,
                'external_id' => $id,
                'quantity' => 1,
                'data' => [],
                'synced_at' => now(),
            ]);
        }
    }

    protected function failed(string $queue): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => now(),
        ]);
    }
}

/** Reads its ids from the price list, like every spreadsheet supplier. */
class DemoDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return SupplierStock::where('supplier_id', $this->supplier->id)->pluck('external_id');
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}
