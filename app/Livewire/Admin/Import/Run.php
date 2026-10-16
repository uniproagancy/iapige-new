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

class Run extends Component
{
    public string $picked = '';

    public array $selected = [];

    public bool $selectAll = false;

    public string $working = '';

    public int $processed = 0;

    public int $startedWith = 0;

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

    public function processNext(): void
    {

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

            'queued' => (int) $waiting->sum(),

            'busy' => $suppliers->mapWithKeys(fn (Supplier $s) => [
                $s->code => (int) ($waiting['import:'.$s->code] ?? 0),
            ]),

            'command' => 'php artisan queue:work --queue='
                .($suppliers->isEmpty()
                    ? 'import'
                    : $suppliers->pluck('code')->map(fn ($c) => 'import:'.$c)->implode(','))
                .' --timeout=360',
        ])->layout('layouts.admin', ['title' => __('admin.run_title')]);
    }
}
