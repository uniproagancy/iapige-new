<?php

namespace App\Livewire\Admin\Import;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Support\Redact;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Starting a supplier's import from the admin.
 *
 * Queueing a run has only ever been an artisan command, which means somebody
 * has to be at a terminal to start the nightly work — and the person who
 * decides a supplier needs refreshing is usually the one looking at its
 * products, not the one with a shell open.
 *
 * So this queues the jobs. It does not process them: a worker still has to be
 * running, and the counts here say plainly whether one is, because a queue
 * that only grows is the thing people spend an afternoon not noticing.
 */
class Run extends Component
{
    /** The supplier chosen in the picker, if the operator is using it. */
    public string $picked = '';

    /** @var array<int, string> codes ticked in the table */
    public array $selected = [];

    public bool $selectAll = false;

    /* ------------------------------------------------------------------ working here */

    /** The supplier whose queue the browser is working through, if any. */
    public string $working = '';

    public int $processed = 0;

    public int $startedWith = 0;

    /** Ticking the header ticks every supplier that can actually run. */
    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? Supplier::where('is_active', true)->pluck('code')->all()
            : [];
    }

    public function run(ImportManager $manager, ?string $code = null): void
    {
        $code = $code ?: $this->picked;

        $supplier = Supplier::where('code', $code)->first();

        if (! $supplier) {
            $this->dispatch('toast', type: 'error', message: __('admin.run_pick_one'));

            return;
        }

        try {
            $queued = $manager->run($supplier);
        } catch (\Throwable $e) {
            Log::channel('import')->error('could not queue the import', [
                'supplier' => $supplier->code,
                'error' => Redact::secrets($e->getMessage()),
            ]);

            $this->dispatch('toast', type: 'error', message: __('admin.run_failed_toast', [
                'error' => $e->getMessage(),
            ]));

            return;
        }

        $this->dispatch('toast', message: $queued
            ? __('admin.run_queued', ['count' => $queued, 'supplier' => $supplier->name])
            : __('admin.run_nothing', ['supplier' => $supplier->name]));
    }

    /**
     * Queues every supplier that was ticked.
     *
     * Each is queued on its own, so one that cannot be started — a driver
     * that no longer exists, a source that refuses — does not take the rest
     * of the selection with it. The count at the end is what actually went
     * on the queue, not what was asked for.
     */
    public function runSelected(ImportManager $manager): void
    {
        if (! $this->selected) {
            $this->dispatch('toast', type: 'error', message: __('admin.run_pick_one'));

            return;
        }

        $queued = 0;
        $ran = 0;
        $failed = [];

        foreach (Supplier::whereIn('code', $this->selected)->where('is_active', true)->get() as $supplier) {
            try {
                $queued += $manager->run($supplier);
                $ran++;
            } catch (\Throwable $e) {
                $failed[] = $supplier->code;

                Log::channel('import')->error('could not queue the import', [
                    'supplier' => $supplier->code,
                    'error' => Redact::secrets($e->getMessage()),
                ]);
            }
        }

        $this->selected = [];
        $this->selectAll = false;

        $this->dispatch('toast',
            type: $failed ? 'error' : 'success',
            message: $failed
                ? __('admin.run_some_failed', ['count' => $queued, 'failed' => implode(', ', $failed)])
                : __('admin.run_bulk_queued', ['count' => $queued, 'suppliers' => $ran]),
        );
    }

    /**
     * Works through a supplier's queue from the browser.
     *
     * A worker is the right way to do this and will always be, but it needs a
     * terminal and a process that stays alive — and on shared hosting that is
     * a job in itself. For a few hundred products it is quicker to leave the
     * tab open.
     *
     * Only starts: the first job runs on the next request, so the button
     * answers at once rather than appearing to hang.
     */
    public function workHere(string $code): void
    {
        $this->working = $code;
        $this->processed = 0;
        $this->startedWith = $this->queued($code);

        if (! $this->startedWith) {
            $this->working = '';

            $this->dispatch('toast', message: __('admin.run_queue_empty'));
        }
    }

    public function stopWorking(): void
    {
        $this->working = '';
    }

    /**
     * One job, then back to the browser.
     *
     * Through the framework's own worker rather than by calling the job
     * directly, so everything a real worker does still happens: the rate
     * limit is respected, a failure is recorded as a failure, and a job that
     * asks to be retried is released rather than lost.
     *
     * One per request, because a request cannot be killed the way a worker
     * process can — a batch of them would take the whole page down with the
     * first slow supplier.
     */
    public function processNext(): void
    {
        /*
         * Resolved by name, not type.
         *
         * Worker's constructor takes a closure the container cannot make
         * on its own, so asking for the class gives "unresolvable
         * dependency". The queue provider binds a built one under this
         * name, which is the same worker queue:work uses.
         */
        $worker = app('queue.worker');

        if (! $this->working) {
            return;
        }

        $queue = 'import:'.$this->working;

        if (! $this->queued($this->working)) {
            $this->dispatch('toast', message: __('admin.run_queue_finished', [
                'count' => $this->processed,
            ]));

            $this->working = '';

            return;
        }

        try {
            $worker->runNextJob(config('queue.default'), $queue, new WorkerOptions(
                maxTries: 3,
                // a browser request has no alarm to kill it, so this is the
                // job's own declared timeout doing the work
                timeout: 0,
            ));

            $this->processed++;
        } catch (\Throwable $e) {
            Log::channel('import')->warning('a job failed while being worked from the admin', [
                'queue' => $queue,
                'error' => Redact::secrets($e->getMessage()),
            ]);
        }
    }

    /**
     * Throws away a supplier's waiting jobs.
     *
     * A queue left over from a run that was started twice, or one pointed at
     * a source that has since changed, is worth emptying rather than waiting
     * out — and emptying it is the only way to queue a fresh run that is not
     * simply added to the back of the old one.
     */
    public function clearQueue(string $code): void
    {
        $removed = DB::table('jobs')->where('queue', 'import:'.$code)->delete();

        if ($this->working === $code) {
            $this->working = '';
        }

        $this->dispatch('toast', message: __('admin.run_queue_cleared', ['count' => $removed]));
    }

    protected function queued(string $code): int
    {
        return (int) DB::table('jobs')->where('queue', 'import:'.$code)->count();
    }

    /**
     * Throws away a supplier's failed jobs.
     *
     * A job that failed carries the rules it was queued with, so retrying one
     * from before a fix fails again by the old rules and looks exactly like
     * the fix not working. Clearing them and running again is the cure.
     */
    public function clearFailed(string $code): void
    {
        $removed = DB::table('failed_jobs')->where('queue', 'import:'.$code)->delete();

        $this->dispatch('toast', message: __('admin.run_cleared', ['count' => $removed]));
    }

    public function render()
    {
        $suppliers = Supplier::orderBy('priority')->orderBy('name')->get();

        $waiting = DB::table('jobs')
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue');

        $failed = DB::table('failed_jobs')
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue');

        $rows = SupplierStock::selectRaw('supplier_id, count(*) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $products = ProductOffer::selectRaw('supplier_id, count(*) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        return view('livewire.admin.import.run', [
            'suppliers' => $suppliers->map(fn (Supplier $s) => [
                'model' => $s,
                'waiting' => (int) ($waiting['import:'.$s->code] ?? 0),
                'failed' => (int) ($failed['import:'.$s->code] ?? 0),
                'rows' => (int) ($rows[$s->id] ?? 0),
                'products' => (int) ($products[$s->id] ?? 0),
            ]),
            // the total is what says whether a worker is running at all
            'queued' => (int) $waiting->sum(),

            /*
             * How many jobs each supplier already has waiting.
             *
             * A run queues one job per product, so starting a supplier
             * that is still working through the last run doubles the
             * queue. Nothing is written twice — the import updates what
             * it finds — but the second copy costs the same hour of
             * fetching as the first, which is the whole of the damage
             * and quite enough to want a question first.
             */
            'busy' => $suppliers->mapWithKeys(fn (Supplier $s) => [
                $s->code => (int) ($waiting['import:'.$s->code] ?? 0),
            ]),

            /*
             * The command, with the queues it actually has to listen to.
             *
             * Jobs go on a queue named after their supplier, so a worker
             * started on "import" hears none of them — it sits there looking
             * healthy while the count on this page never moves.
             */
            'command' => 'php artisan queue:work --queue='
                .($suppliers->isEmpty()
                    ? 'import'
                    : $suppliers->pluck('code')->map(fn ($c) => 'import:'.$c)->implode(','))
                .' --timeout=360',
        ])->layout('layouts.admin', ['title' => __('admin.run_title')]);
    }
}
