<?php

namespace App\Livewire\Admin\Import;

use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Services\Import\ImportManager;
use App\Support\Redact;
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
        ])->layout('layouts.admin', ['title' => __('admin.run_title')]);
    }
}
