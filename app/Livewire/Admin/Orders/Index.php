<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The order list.
 *
 * Opens on what needs doing rather than on everything ever sold: a shop is
 * worked from the top of this screen, and a paid order waiting to be packed is
 * the only thing that is urgent.
 */
class Index extends Component
{
    use WithPagination;

    /** The order a status moves through; anything else is an end state. */
    public const FLOW = ['new', 'confirmed', 'packed', 'shipped', 'completed'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'pay', except: '')]
    public string $payment = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    public array $selected = [];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'payment', 'from', 'to', 'perPage'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /* ------------------------------------------------------------------ query */

    protected function query()
    {
        return Order::query()
            ->with('items')
            ->when($this->search, function ($q) {
                $term = trim($this->search);

                // one box for the three things a caller ever quotes
                $q->where(fn ($w) => $w
                    ->where('number', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->payment, fn ($q) => $q->where('payment_method', $this->payment))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id');
    }

    public function render()
    {
        return view('livewire.admin.orders.index', [
            'orders'   => $this->query()->paginate($this->perPage),
            'counts'   => $this->statusCounts(),
            'statuses' => $this->statuses(),
            'totals'   => $this->totals(),
        ])->layout('layouts.admin', ['title' => __('admin.orders')]);
    }

    /** @return array<string, string> */
    public function statuses(): array
    {
        return [
            'new'       => __('order.status.new'),
            'confirmed' => __('order.status.confirmed'),
            'packed'    => __('order.status.packed'),
            'shipped'   => __('order.status.shipped'),
            'completed' => __('order.status.completed'),
            'cancelled' => __('order.status.cancelled'),
            'returned'  => __('order.status.returned'),
        ];
    }

    /** @return array<string, int> */
    protected function statusCounts(): array
    {
        return Order::selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    /** What the current filter is worth, so the numbers match what is on screen. */
    protected function totals(): array
    {
        $row = $this->query()->reorder()
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue')
            ->first();

        return [
            'orders'  => (int) ($row->orders ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
        ];
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'payment', 'from', 'to']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status || $this->payment || $this->from || $this->to;
    }

    /* ------------------------------------------------------------------ actions */

    /** Moves an order one step along the flow, from the list. */
    public function advance(int $id): void
    {
        $order = Order::findOrFail($id);
        $position = array_search($order->status, self::FLOW, true);

        if ($position === false || $position === count(self::FLOW) - 1) {
            return;
        }

        $this->setStatus($order, self::FLOW[$position + 1]);
    }

    public function bulkAdvance(): void
    {
        foreach (Order::whereKey($this->selected)->get() as $order) {
            $position = array_search($order->status, self::FLOW, true);

            if ($position !== false && $position < count(self::FLOW) - 1) {
                $this->setStatus($order, self::FLOW[$position + 1], silent: true);
            }
        }

        $count = count($this->selected);
        $this->selected = [];

        $this->dispatch('toast', message: __('admin.orders_advanced', ['count' => $count]));
    }

    protected function setStatus(Order $order, string $status, bool $silent = false): void
    {
        $from = $order->status;

        DB::transaction(function () use ($order, $status, $from) {
            $order->update(['status' => $status]);

            $order->events()->create([
                'user_id' => auth()->id(),
                'type'    => 'status',
                'from'    => $from,
                'to'      => $status,
            ]);
        });

        if (! $silent) {
            $this->dispatch('toast', message: __('admin.order_status_changed', [
                'number' => $order->number,
                'status' => $this->statuses()[$status] ?? $status,
            ]));
        }
    }
}
