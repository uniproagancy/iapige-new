<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\Drivers\CredoInstallment;
use App\Services\Payments\Drivers\CredoSplit;
use Illuminate\Console\Command;

/*
 | Prints, for a real order, exactly what the shop sends Credo and exactly what
 | it asks them back — so a question to their support carries the request
 | itself rather than a description of it.
 */
class CredoRequest extends Command
{
    protected $signature = 'credo:request
        {order : the order number, or its id}
        {--split : the split product rather than the instalment one}';

    protected $description = 'Show the Credo requests for one real order';

    public function handle(): int
    {
        $order = Order::where('number', $this->argument('order'))
            ->orWhere('id', $this->argument('order'))
            ->with('items')
            ->first();

        if (! $order) {
            $this->error('No order with that number.');

            return self::FAILURE;
        }

        if ($order->items->isEmpty()) {
            $this->error('That order carries no items, so there is nothing to send.');

            return self::FAILURE;
        }

        $driver = $this->option('split') ? new CredoSplit : new CredoInstallment;

        $payload = $driver->payload($order);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->line('');
        $this->info('Order '.$order->number.'  —  '.money($order->total).'  —  '.$driver->driverName());
        $this->line('');

        $this->table(['item', 'qty', 'shelf price', 'sent to Credo (tetri)'], $order->items->map(fn ($item) => [
            mb_substr($item->name, 0, 44),
            $item->qty,
            money($item->price),
            (int) round(((float) $item->price * (1 + $this->fee($driver))) * 100),
        ])->all());

        $this->line('');
        $this->line(str_repeat('-', 64));
        $this->line('1. Starting the application — the shopper is posted here');
        $this->line(str_repeat('-', 64));
        $this->line('');
        $this->line('POST '.$driver->widgetUrl());
        $this->line('field: '.$driver->fieldName());
        $this->line('');
        $this->line($json);
        $this->line('');
        $this->line('curl -X POST '.$this->quote($driver->widgetUrl()).' \\');
        $this->line('  --data-urlencode '.$this->quote($driver->fieldName().'='.$json));

        $this->line('');
        $this->line(str_repeat('-', 64));
        $this->line('2. Asking where it stands');
        $this->line(str_repeat('-', 64));
        $this->line('');

        $this->status($driver, (string) $order->number);

        $this->line('');
        $this->line(str_repeat('-', 64));
        $this->line('3. Where Credo may call us');
        $this->line(str_repeat('-', 64));
        $this->line('');
        $this->line('POST '.route('payment.callback', ['driver' => $driver->driverName()]));

        $this->line('');

        return self::SUCCESS;
    }

    protected function status(CredoInstallment $driver, string $code): void
    {
        $url = $driver->statusUrl();
        $merchant = $driver->shopId();

        if (! config($driver->configKey().'.secret')) {
            $this->warn('CREDO_SECRET is not set, so the hash cannot be produced.');
            $this->line('');
            $this->line('GET '.$url.'?merchantId='.$merchant.'&orderCode='.$code.'&hash=<MD5('.$merchant.$code.' + secret)>');

            return;
        }

        $full = $url.'?merchantId='.$merchant.'&orderCode='.$code.'&hash='.$driver->hashFor($code);

        $this->line('GET '.$full);
        $this->line('');
        $this->line('curl '.$this->quote($full));
    }

    protected function fee(CredoInstallment $driver): float
    {
        return (float) config($driver->configKey().'.fee', 0.10);
    }

    protected function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
