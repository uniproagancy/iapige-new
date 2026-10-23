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
            'paidThisMonth' => $this->paidThisMonth(),
            'week' => $this->week(),
        ])->layout('layouts.admin', ['title' => __('admin.dashboard')]);
    }

    /*
     | Money that has actually arrived, counted on the day it arrived. The
     | takings used to be the sum of every order written — unpaid, cancelled
     | and deleted alike — which read far higher than the shop had earned.
     */
    protected function today(): array
    {
        $placed = Order::selectRaw('date(created_at) as day, count(*) as orders')
            ->whereDate('created_at', '>=', now()->subDay()->toDateString())
            ->groupBy('day')
            ->pluck('orders', 'day');

        $taken = Order::selectRaw('date(paid_at) as day, coalesce(sum(total), 0) as revenue')
            ->where('is_paid', true)
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', now()->subDay()->toDateString())
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        return [
            'orders' => (int) ($placed[$today] ?? 0),
            'revenue' => (float) ($taken[$today] ?? 0),
            'orders_yesterday' => (int) ($placed[$yesterday] ?? 0),
            'revenue_yesterday' => (float) ($taken[$yesterday] ?? 0),

            /* What has been ordered and not yet paid for. */
            'owed' => (float) Order::where('is_paid', false)
                ->whereIn('status', Order::OPEN)
                ->sum('total'),
        ];
    }

    /* The month so far, on the same footing: paid only. */
    protected function paidThisMonth(): array
    {
        $since = now()->startOfMonth();

        return [
            'orders' => Order::where('is_paid', true)->where('paid_at', '>=', $since)->count(),
            'revenue' => (float) Order::where('is_paid', true)->where('paid_at', '>=', $since)->sum('total'),
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
        $rows = Order::selectRaw('date(paid_at) as day, coalesce(sum(total), 0) as revenue')
            ->where('is_paid', true)
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', now()->subDays(6)->toDateString())
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
