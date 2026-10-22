<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Services\Payments\PaymentManager;
use Illuminate\Console\Command;

class WirePaymentMethods extends Command
{
    protected $signature = 'payments:wire {--dry : show what would change and stop}';

    protected $description = 'Point each payment method at its driver, once its keys are in .env';

    /* code => [driver, the config key that has to be filled in for it to work] */
    protected const WIRING = [
        'bog_card' => ['bog-card', 'bog.payment.secret_key'],
        'bog_installment' => ['bog-installment', 'bog.installment.secret_key'],
        'bog_split' => ['bog-installment', 'bog.installment.secret_key'],
        'tbc_installment' => ['tbc-installment', 'tbc.installment.merchant_key'],
        'credo_installment' => ['credo-installment', 'credo.merchant_id'],
        'credo_split' => ['credo-split', 'credo.split.merchant_id'],
    ];

    public function handle(PaymentManager $payments): int
    {
        $rows = [];
        $changed = 0;

        foreach (PaymentMethod::orderBy('sort_order')->get() as $method) {
            [$driver, $key] = self::WIRING[$method->code] ?? [null, null];

            if (! $driver) {
                $rows[] = [$method->code, $method->driver ?: '—', 'offline method, left alone'];

                continue;
            }

            if (! $payments->has($driver)) {
                $rows[] = [$method->code, $method->driver ?: '—', "no driver named {$driver}"];

                continue;
            }

            if (! config($key)) {
                $rows[] = [$method->code, $method->driver ?: '—', "waiting for {$key}"];

                continue;
            }

            $needs = $method->driver !== $driver || ! $method->is_active || ! $method->is_online;

            if (! $needs) {
                $rows[] = [$method->code, $driver, 'already wired'];

                continue;
            }

            if (! $this->option('dry')) {
                $method->update(['driver' => $driver, 'is_active' => true, 'is_online' => true]);
            }

            $changed++;
            $rows[] = [$method->code, $driver, $this->option('dry') ? 'would be wired' : 'wired'];
        }

        $this->table(['method', 'driver', 'what happened'], $rows);

        if ($this->option('dry')) {
            $this->line("  {$changed} would change. Run it without --dry to apply.");

            return self::SUCCESS;
        }

        $this->info($changed ? "{$changed} method(s) wired." : 'Nothing to change.');

        if ($this->shouldWarn()) {
            $this->newLine();
            $this->warn('Some methods are still waiting for keys — the rows above say which.');
        }

        return self::SUCCESS;
    }

    protected function shouldWarn(): bool
    {
        foreach (self::WIRING as [$driver, $key]) {
            if (! config($key)) {
                return true;
            }
        }

        return false;
    }
}
