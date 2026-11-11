<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const FLOW = [Order::NEW, Order::VIEWED, Order::COMPLETED];

    /*
     | An order nobody has to touch again: sold and paid for. It stays in the
     | shop's records on a chip of its own, out of the way of the day's work,
     | as the cancelled ones are on theirs. A completed order still unpaid is
     | not done -- somebody has the money to chase -- so it stays in the list.
     */
    public const DONE = 'done';

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
            ->with(['items', 'pickedUp.user:id,name'])
            ->when(trim($this->search) !== '', function ($q) {
                /* "#IAPI-…" is how the list writes a number, so it is how one
                   gets copied back in. */
                $term = ltrim(trim($this->search), '#');
                $digits = (string) preg_replace('/\D/', '', $term);

                /* The same telephone is kept as +995599… and as 599…; the
                   country code typed in front would miss the second. */
                if (strlen($digits) >= 12 && str_starts_with($digits, '995')) {
                    $digits = substr($digits, 3);
                }

                $q->where(fn ($w) => $w
                    ->where('number', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    /* Only a search with a telephone's worth of figures in it
                       looks at telephones. A name has none, and an empty
                       pattern matched every order there is. */
                    ->when(strlen($digits) >= 3, fn ($p) => $p->orWhereRaw(
                        "replace(replace(phone, ' ', ''), '-', '') like ?",
                        ['%'.$digits.'%'],
                    )));
            })
            ->when($this->status === self::DONE, fn ($q) => $q
                ->where('status', Order::COMPLETED)
                ->where('is_paid', true))
            /* Somebody searching for a number wants that order, finished or
               not; the archive is only kept out of the open list. */
            ->when(
                $this->status === '' && $this->search === '',
                fn ($q) => $this->inHand($q),
            )
            ->when(
                $this->status !== '' && $this->status !== self::DONE,
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

    /* Everything not yet finished: what is still open, and what was sold but
       not yet paid for. */
    protected function inHand($query)
    {
        return $query->where(fn ($open) => $open
            ->whereNotIn('status', self::ARCHIVED)
            ->orWhere(fn ($unpaid) => $unpaid->where('status', Order::COMPLETED)->where('is_paid', false)));
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
            'done' => Order::where('status', Order::COMPLETED)->where('is_paid', true)->count(),
            'inHand' => $this->inHand(Order::query())->count(),
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
        Gate::authorize('admin');
        $order = Order::findOrFail($id);
        $position = array_search($order->status, self::FLOW, true);

        if ($position === false || $position === count(self::FLOW) - 1) {
            return;
        }

        $this->setStatus($order, self::FLOW[$position + 1]);
    }

    public function bulkAdvance(): void
    {
        Gate::authorize('admin');
        $count = DB::transaction(function () {
            $orders = Order::whereKey($this->selected)->lockForUpdate()->get();
            foreach ($orders as $order) {
                $position = array_search($order->status, self::FLOW, true);
                if ($position !== false && $position < count(self::FLOW) - 1) {
                    $this->assertTransferCompletion($order, self::FLOW[$position + 1]);
                }
            }
            $changed = 0;
            foreach ($orders as $order) {
                $position = array_search($order->status, self::FLOW, true);
                if ($position !== false && $position < count(self::FLOW) - 1) {
                    $changed += (int) $this->setStatus($order, self::FLOW[$position + 1], silent: true);
                }
            }

            return $changed;
        });
        $this->selected = [];

        $this->dispatch('toast', message: __('admin.orders_advanced', ['count' => $count]));
    }

    protected function assertTransferCompletion(Order $order, string $status): void
    {
        if ($status !== Order::COMPLETED || $order->payment !== 'transfer') {
            return;
        }
        if (trim((string) $order->payment_bank) === '') {
            throw ValidationException::withMessages(['paymentBank' => __('delivery.transfer_bank_required_order', ['number' => $order->number])]);
        }
        if (! $order->is_paid) {
            throw ValidationException::withMessages(['transferPayment' => __('delivery.transfer_payment_required_order', ['number' => $order->number])]);
        }
    }

    protected function setStatus(Order $order, string $status, bool $silent = false): bool
    {
        Gate::authorize('admin');
        $changed = DB::transaction(function () use ($order, $status) {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->status === $status) {
                return false;
            }
            $this->assertTransferCompletion($current, $status);
            $from = $current->status;
            $current->update(['status' => $status]);

            $current->events()->create([
                'user_id' => auth()->id(),
                'type' => 'status',
                'from' => $from,
                'to' => $status,
            ]);

            return true;
        });

        if ($changed && ! $silent) {
            $this->dispatch('toast', message: __('admin.order_status_changed', [
                'number' => $order->number,
                'status' => $this->statuses()[$status] ?? $status,
            ]));
        }

        return $changed;
    }
}
