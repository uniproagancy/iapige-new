<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderPixelData;
use App\Services\Facebook\Pixel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 | Reports a sale to Meta by hand. A sale finished while something was in the
 | way -- no snapshot, nothing listening, the shop offline -- is reported once
 | and never again, so there is nothing to undo and no way to count it twice.
 */
class PixelPurchase extends Command
{
    protected $signature = 'pixel:purchase
        {number? : the order number, as the shop writes it}
        {--missing : every sale Meta has not been told about}
        {--dry : say what would be reported and report nothing}';

    protected $description = 'Report a sale to Meta that was never reported';

    public function handle(Pixel $pixel): int
    {
        $orders = $this->orders();

        if ($orders->isEmpty()) {
            $this->line('Nothing to report.');

            return self::SUCCESS;
        }

        $reported = $skipped = 0;

        foreach ($orders as $order) {
            $snapshot = OrderPixelData::where('order_id', $order->id)->first();
            $why = $this->inTheWay($order, $snapshot);

            if ($why) {
                $this->line('  '.$order->number.'  '.$why);
                $skipped++;

                continue;
            }

            if ($this->option('dry')) {
                $this->line('  '.$order->number.'  would be reported, '.money($order->total));
                $reported++;

                continue;
            }

            /* An order taken by hand has no snapshot of its own, and the one
               it is given carries the customer rather than the operator. */
            $pixel->snapshotForStaffOrder($order);

            if ($pixel->reportPurchase($order, offline: true)) {
                $this->info('  '.$order->number.'  reported, '.money($order->total));
                $reported++;

                continue;
            }

            $this->error('  '.$order->number.'  Meta refused it; see the pixel log');
            $skipped++;
        }

        $this->line('');
        $this->line($reported.($this->option('dry') ? ' to report' : ' reported').', '.$skipped.' left');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Order>
     */
    protected function orders()
    {
        if ($number = $this->argument('number')) {
            return Order::where('number', $number)->with('items')->get();
        }

        if (! $this->option('missing')) {
            $this->error('Name an order, or ask for --missing.');

            return collect();
        }

        return Order::query()
            ->where('status', '!=', Order::CANCELLED)
            ->where(fn ($q) => $q->where('is_paid', true)->orWhere('status', Order::COMPLETED))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_pixel_data')
                ->whereColumn('order_pixel_data.order_id', 'orders.id')
                ->whereNotNull('sent_at'))
            ->with('items')
            ->orderBy('id')
            ->get();
    }

    protected function inTheWay(Order $order, ?OrderPixelData $snapshot): ?string
    {
        if ($order->status === Order::CANCELLED) {
            return 'cancelled';
        }

        if (! $order->is_paid && $order->status !== Order::COMPLETED) {
            return 'not a sale yet: '.$order->status.', unpaid';
        }

        if ($snapshot?->alreadySent()) {
            return 'already reported on '.$snapshot->sent_at->toDateTimeString();
        }

        return null;
    }
}
