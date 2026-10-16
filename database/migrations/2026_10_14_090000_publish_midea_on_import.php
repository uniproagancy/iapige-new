<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->set(true);
    }

    public function down(): void
    {
        $this->set(false);
    }

    protected function set(bool $value): void
    {
        $supplier = Supplier::where('code', 'midea')->first();

        if (! $supplier) {
            return;
        }

        $supplier->update([
            'config' => array_merge($supplier->config ?? [], ['publish_on_import' => $value]),
        ]);
    }
};
