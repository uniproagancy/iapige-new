<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportQueueStatus extends Command
{
    protected $signature = 'import:queue {supplier? : the supplier code}';

    protected $description = 'Show what is waiting, what failed and the rules each job carries';

    public function handle(): int
    {
        $this->waiting();
        $this->rules();
        $this->failures();

        return self::SUCCESS;
    }

    protected function waiting(): void
    {
        $rows = DB::table('jobs')
            ->selectRaw('queue, count(*) as total, min(available_at) as oldest, max(attempts) as attempts')
            ->when($this->queue(), fn ($q, $queue) => $q->where('queue', $queue))
            ->groupBy('queue')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('nothing waiting.');

            return;
        }

        $this->table(
            ['queue', 'waiting', 'oldest', 'most attempts'],
            $rows->map(fn ($r) => [
                $r->queue,
                $r->total,
                Carbon::createFromTimestamp($r->oldest)->diffForHumans(),
                $r->attempts,
            ]),
        );
    }

    protected function rules(): void
    {
        $sample = DB::table('jobs')
            ->when($this->queue(), fn ($q, $queue) => $q->where('queue', $queue))
            ->orderBy('id')
            ->first();

        if (! $sample) {
            return;
        }

        $payload = json_decode($sample->payload, true) ?: [];
        $until = $payload['retryUntil'] ?? null;

        $this->line('');
        $this->line('the oldest waiting job carries:');
        $this->line('  maxTries    '.($payload['maxTries'] ?? 'none'));
        $this->line('  retryUntil  '.($until ? Carbon::createFromTimestamp($until)->diffForHumans() : 'none'));

        if (! $until) {
            $this->warn('  → queued before the deadline fix; clear the queue and run the import again.');
        }
    }

    protected function failures(): void
    {
        $rows = DB::table('failed_jobs')
            ->when($this->queue(), fn ($q, $queue) => $q->where('queue', $queue))
            ->orderByDesc('failed_at')
            ->limit(200)
            ->get();

        $this->line('');

        if ($rows->isEmpty()) {
            $this->info('no failed jobs.');

            return;
        }

        $grouped = $rows
            ->groupBy(fn ($r) => str($r->exception)->before("\n")->limit(90)->toString())
            ->map->count()
            ->sortDesc();

        $this->table(
            ['failed '.$rows->count().' (latest 200)', 'count'],
            $grouped->map(fn ($count, $error) => [$error, $count])->values(),
        );

        $this->line('');
        $this->line('  "attempted too many times" with nothing under it is a job that was');
        $this->line('  killed or postponed, never one that threw — see import:queue rules above.');
    }

    protected function queue(): ?string
    {
        if (! $code = $this->argument('supplier')) {
            return null;
        }

        if (! Supplier::where('code', $code)->exists()) {
            $this->warn("no supplier with the code {$code}; showing that queue anyway.");
        }

        return 'import:'.$code;
    }
}
