<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->set(30);
    }

    public function down(): void
    {
        $this->set(null);
    }

    protected function set(?int $rate): void
    {
        $supplier = Supplier::where('code', 'midea')->first();

        if (! $supplier) {
            return;
        }

        $config = $supplier->config ?? [];

        if ($rate === null) {
            unset($config['rate']);
        } else {
            $config['rate'] = $rate;
        }

        $supplier->update(['config' => $config]);
    }
};
