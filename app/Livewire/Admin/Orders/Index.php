<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

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

    protected function query()
    {
        return Order::query()
            ->with('items')
            ->when($this->search, function ($q) {
                $term = trim($this->search);

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
            'orders' => $this->query()->paginate($this->perPage),
            'counts' => $this->statusCounts(),
            'statuses' => $this->statuses(),
            'totals' => $this->totals(),
        ])->layout('layouts.admin', ['title' => __('admin.orders')]);
    }

    public function statuses(): array
    {
        return [
            'new' => __('order.status.new'),
            'confirmed' => __('order.status.confirmed'),
            'packed' => __('order.status.packed'),
            'shipped' => __('order.status.shipped'),
            'completed' => __('order.status.completed'),
            'cancelled' => __('order.status.cancelled'),
            'returned' => __('order.status.returned'),
        ];
    }

    protected function statusCounts(): array
    {
        return Order::selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    protected function totals(): array
    {
        $row = $this->query()->reorder()
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
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
                'type' => 'status',
                'from' => $from,
                'to' => $status,
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
