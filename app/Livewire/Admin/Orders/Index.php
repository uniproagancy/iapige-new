<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const FLOW = [Order::NEW, Order::VIEWED, Order::COMPLETED];

    /* An order nobody has to touch again. It stays in the shop's records and
       on its own chip, but it is out of the way of the day's work. */
    public const ARCHIVE = 'archive';

    public const ARCHIVED = Order::ARCHIVED;

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
            ->when($this->status === self::ARCHIVE, fn ($q) => $q->whereIn('status', self::ARCHIVED))
            /* Somebody searching for a number wants that order, finished or
               not; the archive is only kept out of the open list. */
            ->when(
                $this->status === '' && $this->search === '',
                fn ($q) => $q->whereNotIn('status', self::ARCHIVED),
            )
            ->when(
                $this->status !== '' && $this->status !== self::ARCHIVE,
                fn ($q) => $q->where('status', $this->status),
            )
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
        $orders = $this->query()->paginate($this->perPage);

        return view('livewire.admin.orders.index', [
            'orders' => $orders,
            'counts' => $this->statusCounts(),
            'statuses' => $this->statuses(),
            'totals' => $this->totals(),
            'methods' => PaymentMethod::orderBy('sort_order')->orderBy('id')->get(),
            'canDelete' => Gate::allows('owner'),

            /* Which rows have an online payment still waiting on the bank. */
            'awaiting' => Order::whereKey($orders->pluck('id'))
                ->where('is_paid', false)
                ->whereHas('transactions', fn ($q) => $q->whereIn('status', [
                    PaymentTransaction::PENDING,
                    PaymentTransaction::FAILED,
                ]))
                ->pluck('id')
                ->flip(),
            'archived' => Order::whereIn('status', self::ARCHIVED)->count(),
        ])->layout('layouts.admin', ['title' => __('admin.orders')]);
    }

    public function statuses(): array
    {
        return Order::labels();
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

    /* The same question the order page asks, from the row — so a shelf full
       of orders can be settled without opening each one. */
    public function recheckPayment(int $id): void
    {
        $order = Order::find($id);

        if (! $order || $order->is_paid) {
            return;
        }

        $answer = app(PaymentCheck::class)->ask($order);

        $this->dispatch('toast', message: '#'.$order->number.' — '.$answer['message'], type: $answer['type']);
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
