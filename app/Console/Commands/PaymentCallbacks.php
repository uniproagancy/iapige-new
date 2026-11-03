<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentCallback;
use Illuminate\Console\Command;

/*
 | What the banks have sent, for reading before writing logic against it:
 |
 |   php artisan payments:callbacks                     the last twenty
 |   php artisan payments:callbacks --driver=credo-installment --outcome=unmatched
 |   php artisan payments:callbacks --order=IAPI-261103-00002
 |   php artisan payments:callbacks --show=42           one, in full
 */
class PaymentCallbacks extends Command
{
    protected $signature = 'payments:callbacks
        {--driver= : only this driver}
        {--order= : only callbacks matched to this order number}
        {--outcome= : paid, not_paid, unmatched, unknown_driver or failed}
        {--last=20 : how many}
        {--show= : print one callback in full}';

    protected $description = 'List the callbacks the banks have sent, as they arrived';

    public function handle(): int
    {
        if ($id = $this->option('show')) {
            return $this->show((int) $id);
        }

        $query = PaymentCallback::query()->with('order:id,number')->latest('id');

        if ($driver = $this->option('driver')) {
            $query->where('driver', $driver);
        }

        if ($outcome = $this->option('outcome')) {
            $query->where('outcome', $outcome);
        }

        if ($number = $this->option('order')) {
            $query->where('order_id', Order::where('number', $number)->value('id') ?? 0);
        }

        $rows = $query->limit(max(1, (int) $this->option('last')))->get();

        if ($rows->isEmpty()) {
            $this->info('No callbacks recorded.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'when', 'driver', 'method', 'order', 'outcome', 'status', 'ip', 'ms', 'fields'],
            $rows->map(fn (PaymentCallback $c) => [
                $c->id,
                $c->created_at?->format('Y-m-d H:i:s'),
                $c->driver,
                $c->method,
                $c->order?->number ?? '—',
                $c->outcome ?? '—',
                trim(($c->status_before ?? '').' → '.($c->status_after ?? ''), ' →') ?: '—',
                $c->ip,
                $c->duration_ms,
                mb_strimwidth(implode(', ', array_keys((array) $c->payload)), 0, 60, '…'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    protected function show(int $id): int
    {
        $callback = PaymentCallback::with('order:id,number')->find($id);

        if (! $callback) {
            $this->error("No callback #{$id}.");

            return self::FAILURE;
        }

        $this->line(json_encode([
            'id' => $callback->id,
            'received' => $callback->created_at?->toIso8601String(),
            'driver' => $callback->driver,
            'method' => $callback->method,
            'path' => $callback->path,
            'ip' => $callback->ip,
            'user_agent' => $callback->user_agent,
            'order' => $callback->order?->number,
            'transaction' => $callback->payment_transaction_id,
            'outcome' => $callback->outcome,
            'confirmed_by_bank' => $callback->confirmed,
            'status' => [$callback->status_before, $callback->status_after],
            'error' => $callback->error,
            'duration_ms' => $callback->duration_ms,
            'query' => $callback->query,
            'headers' => $callback->headers,
            'payload' => $callback->payload,
            'body' => $callback->body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
