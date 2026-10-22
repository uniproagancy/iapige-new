<?php

namespace App\Livewire\Admin\Orders;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const FLOW = ['new', 'viewed', 'confirmed', 'packed', 'shipped', 'completed'];

    /* Not an order status — it narrows the list down to the call requests that
       sit on top of it, so they are one click away instead of one page away. */
    public const CALLBACKS = 'callback';

    /* Enough to see the queue without burying the orders under it. */
    public const PINNED = 10;

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

    #[Url(as: 'paid', except: '')]
    public string $paid = '';

    #[Url(as: 'sort', except: 'new')]
    public string $sort = 'new';

    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    public array $selected = [];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'payment', 'paid', 'from', 'to', 'sort', 'perPage'], true)) {
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
            ->when($this->status === self::CALLBACKS, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($this->status && $this->status !== self::CALLBACKS, fn ($q) => $q->where('status', $this->status))
            ->when($this->payment, fn ($q) => $q->where('payment', $this->payment))
            ->when($this->paid === 'yes', fn ($q) => $q->where('is_paid', true))
            ->when($this->paid === 'no', fn ($q) => $q->where('is_paid', false))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->sort === 'old', fn ($q) => $q->oldest('id'))
            ->when($this->sort === 'total-desc', fn ($q) => $q->orderByDesc('total'))
            ->when($this->sort === 'total-asc', fn ($q) => $q->orderBy('total'))
            ->when($this->sort === 'new', fn ($q) => $q->latest('id'));
    }

    public function render()
    {
        return view('livewire.admin.orders.index', [
            'orders' => $this->query()->paginate($this->perPage),
            'counts' => $this->statusCounts(),
            'statuses' => $this->statuses(),
            'totals' => $this->totals(),
            'methods' => PaymentMethod::orderBy('sort_order')->orderBy('id')->get(),
            'canDelete' => Gate::allows('owner'),
            'callbacks' => $this->callbacks(),
            'callbackCount' => $this->openCallbacks()->count(),
        ])->layout('layouts.admin', ['title' => __('admin.orders')]);
    }

    protected function openCallbacks()
    {
        return CallbackRequest::where('status', CallbackRequest::NEW)
            ->whereNull('order_id')
            ->when($this->search, function ($q) {
                $term = trim($this->search);
                $digits = preg_replace('/\D/', '', $term);

                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")));
            });
    }

    /* They ride above the orders unless the operator narrowed the list to one
       order status, where a lead would only be noise. */
    protected function callbacks()
    {
        if ($this->status !== '' && $this->status !== self::CALLBACKS) {
            return collect();
        }

        return $this->openCallbacks()
            ->with(['product' => fn ($q) => $q->withTranslation()])
            ->latest('id')
            ->when($this->status !== self::CALLBACKS, fn ($q) => $q->limit(self::PINNED))
            ->get();
    }

    public function callCalled(int $id): void
    {
        $request = CallbackRequest::find($id);

        if (! $request) {
            return;
        }

        $request->update([
            'status' => CallbackRequest::CALLED,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        $this->dispatch('toast', message: __('admin.cb_marked_called', ['name' => $request->name]));
    }

    public function callClosed(int $id): void
    {
        $request = CallbackRequest::find($id);

        if (! $request) {
            return;
        }

        $request->update([
            'status' => CallbackRequest::CLOSED,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        $this->dispatch('toast', message: __('admin.cb_marked_closed', ['name' => $request->name]));
    }

    public function statuses(): array
    {
        return [
            'new' => __('order.status.new'),
            'viewed' => __('order.status.viewed'),
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
        $this->reset(['search', 'status', 'payment', 'paid', 'from', 'to']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status || $this->payment || $this->paid || $this->from || $this->to;
    }

    public function deleteOrder(int $id): void
    {
        if (! Gate::allows('owner')) {
            $this->dispatch('toast', message: __('admin.order_delete_denied'), type: 'error');

            return;
        }

        $order = Order::find($id);

        if (! $order) {
            return;
        }

        $number = $order->number;

        $order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'status',
            'from' => $order->status,
            'to' => 'deleted',
        ]);

        $order->delete();

        $this->selected = array_values(array_diff($this->selected, [$id]));

        $this->dispatch('toast', message: __('admin.order_deleted', ['number' => $number]));
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
