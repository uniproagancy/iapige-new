<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_counts_separate_days(): void
    {
        foreach ([[now(), 10], [now(), 15], [now()->subDay(), 99]] as $i => [$at, $total]) {
            DB::table('orders')->insert([
                'number' => 'T-'.$i, 'status' => 'new', 'name' => 'x', 'phone' => '1',
                'subtotal' => $total, 'shipping' => 0, 'total' => $total,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        $m = new \ReflectionMethod(\App\Livewire\Admin\Dashboard::class, 'today');
        $m->setAccessible(true);
        $out = $m->invoke(new \App\Livewire\Admin\Dashboard);

        $this->assertSame(2, $out['orders']);
        $this->assertSame(25.0, $out['revenue']);
        $this->assertSame(1, $out['orders_yesterday']);
    }
}
