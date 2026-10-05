<?php

namespace App\Livewire\Admin;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The first screen of the day.
 *
 * It answers what somebody opening the admin actually wants to know: what came
 * in overnight, what is waiting on me, and what is broken in the catalogue.
 * Everything else belongs on its own page.
 */
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

    /** Today against yesterday, because a number alone says nothing. */
    protected function today(): array
    {
        // one row per day: the count and the takings come back together, so
        // there is no second query that can fall out of step with the first
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

    /** What is on somebody's desk right now. */
    protected function waiting(): array
    {
        return [
            'new_orders' => Order::where('status', 'new')->count(),
            'packing' => Order::whereIn('status', ['confirmed', 'packed'])->count(),
            'unpaid' => Order::where('is_paid', false)
                ->whereIn('status', ['new', 'confirmed', 'packed', 'shipped'])
                ->count(),
            'callbacks' => CallbackRequest::where('status', 'new')->count(),
        ];
    }

    /** Catalogue faults, each a link to the filtered list that fixes them. */
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

    /** Seven days of revenue, for the shape rather than the figures. */
    protected function week(): array
    {
        $rows = DB::table('orders')
            ->selectRaw('date(created_at) as day, coalesce(sum(total), 0) as revenue')
            ->whereDate('created_at', '>=', now()->subDays(6)->toDateString())
            ->whereNotIn('status', ['cancelled', 'returned'])
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
