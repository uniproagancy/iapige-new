<?php

namespace App\Livewire\Admin;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.admin.dashboard.index', [
            'today' => $this->today(),
            'waiting' => $this->waiting(),
            'problems' => $this->problems(),
            'recent' => Order::with('items')->latest('id')->take(8)->get(),
            'week' => $this->week(),
        ])->layout('layouts.admin', ['title' => __('admin.dashboard')]);
    }

    protected function today(): array
    {

        $rows = DB::table('orders')
            ->selectRaw('date(created_at) as day, count(*) as orders, coalesce(sum(total), 0) as revenue')
            ->whereDate('created_at', '>=', now()->subDay()->toDateString())
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $today = $rows->get(now()->toDateString());
        $yesterday = $rows->get(now()->subDay()->toDateString());

        return [
            'orders' => (int) ($today->orders ?? 0),
            'revenue' => (float) ($today->revenue ?? 0),
            'orders_yesterday' => (int) ($yesterday->orders ?? 0),
        ];
    }

    protected function waiting(): array
    {
        return [
            'new_orders' => Order::where('status', 'new')->count(),
            'packing' => Order::where('status', Order::VIEWED)->count(),
            'unpaid' => Order::where('is_paid', false)
                ->whereIn('status', Order::OPEN)
                ->count(),
            'callbacks' => CallbackRequest::where('status', 'new')->count(),
        ];
    }

    protected function problems(): array
    {
        $row = DB::table('products')
            ->selectRaw("
                sum(case when status = 'draft' then 1 else 0 end) as drafts,
                sum(case when category_id is null then 1 else 0 end) as nocat,
                sum(case when price <= 0 then 1 else 0 end) as noprice
            ")
            ->whereNull('deleted_at')
            ->first();

        return [
            'drafts' => (int) ($row->drafts ?? 0),
            'nocat' => (int) ($row->nocat ?? 0),
            'noprice' => (int) ($row->noprice ?? 0),
            'nophoto' => Product::whereDoesntHave('images')->count(),
        ];
    }

    protected function week(): array
    {
        $rows = DB::table('orders')
            ->selectRaw('date(created_at) as day, coalesce(sum(total), 0) as revenue')
            ->whereDate('created_at', '>=', now()->subDays(6)->toDateString())
            ->where('status', '!=', Order::CANCELLED)
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $days = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);

            $days[] = [
                'label' => $date->translatedFormat('D'),
                'revenue' => (float) ($rows[$date->toDateString()] ?? 0),
            ];
        }

        return $days;
    }
}
