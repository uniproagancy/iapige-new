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

    public const FLOW = [Order::NEW, Order::VIEWED, Order::COMPLETED];

    /* Not an order status — it narrows the list down to the call requests that
       sit on top of it, so they are one click away instead of one page away. */
    public const CALLBACKS = 'callback';

    /* An order nobody has to touch again. It stays in the shop's records and
       on its own chip, but it is out of the way of the day's work. */
    public const ARCHIVE = 'archive';

    public const ARCHIVED = Order::ARCHIVED;

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

    public ?int $openCallback = null;

    #[Url(as: 'cbs', except: '')]
    public string $callState = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'payment', 'paid', 'from', 'to', 'sort', 'perPage', 'callState'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->openCallback = null;
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
            ->when($this->status === self::ARCHIVE, fn ($q) => $q->whereIn('status', self::ARCHIVED))
            /* Somebody searching for a number wants that order, finished or
               not; the archive is only kept out of the open list. */
            ->when(
                $this->status === '' && $this->search === '',
                fn ($q) => $q->whereNotIn('status', self::ARCHIVED),
            )
            ->when(
                $this->status !== '' && ! in_array($this->status, [self::CALLBACKS, self::ARCHIVE], true),
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
        return view('livewire.admin.orders.index', [
            'orders' => $this->query()->paginate($this->perPage),
            'counts' => $this->statusCounts(),
            'statuses' => $this->statuses(),
            'totals' => $this->totals(),
            'methods' => PaymentMethod::orderBy('sort_order')->orderBy('id')->get(),
            'canDelete' => Gate::allows('owner'),
            'callbacks' => $this->callbacks(),
            'callbackCount' => $this->openCallbacks()->count(),

            /* The chip stays once a call has ever come in: with the queue empty
               it is the only way back to the handled ones. */
            'callbackTotal' => CallbackRequest::count(),
            'archived' => Order::whereIn('status', self::ARCHIVED)->count(),
            'callStates' => $this->callStates(),
            'callStateCounts' => $this->status === self::CALLBACKS ? $this->callStateCounts() : [],
        ])->layout('layouts.admin', ['title' => __('admin.orders')]);
    }

    protected function callbackQuery()
    {
        return CallbackRequest::query()
            ->when($this->search, function ($q) {
                $term = trim($this->search);
                $digits = preg_replace('/\D/', '', $term);

                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")));
            });
    }

    /* The queue: what nobody has picked up yet. */
    protected function openCallbacks()
    {
        return $this->callbackQuery()
            ->where('status', CallbackRequest::NEW)
            ->whereNull('order_id');
    }

    protected function callbackWith(): array
    {
        return [
            'product' => fn ($q) => $q->withTranslation()->with([
                'images' => fn ($i) => $i->orderBy('sort_order')->limit(1),
            ]),
            'order',
            'handler',
        ];
    }

    /* They ride above the orders unless the operator narrowed the list to one
       order status, where a lead would only be noise. Pressing their own chip
       opens the whole book instead — handled calls included, because they are
       invisible everywhere else once someone has taken them. */
    protected function callbacks()
    {
        if ($this->status === self::CALLBACKS) {
            return $this->callbackQuery()
                ->with($this->callbackWith())
                ->when($this->callState, fn ($q) => $q->where('status', $this->callState))
                ->latest('id')
                ->paginate($this->perPage, pageName: 'cb');
        }

        if ($this->status !== '') {
            return collect();
        }

        return $this->openCallbacks()
            ->with($this->callbackWith())
            ->latest('id')
            ->limit(self::PINNED)
            ->get();
    }

    public function callStates(): array
    {
        return [
            CallbackRequest::NEW => __('admin.cb_new'),
            CallbackRequest::CALLED => __('admin.cb_called'),
            CallbackRequest::CLOSED => __('admin.cb_closed'),
        ];
    }

    protected function callStateCounts(): array
    {
        return $this->callbackQuery()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    public function toggleCallback(int $id): void
    {
        $this->openCallback = $this->openCallback === $id ? null : $id;
    }

    public function callReopen(int $id): void
    {
        $request = CallbackRequest::find($id);

        if (! $request) {
            return;
        }

        $request->update([
            'status' => CallbackRequest::NEW,
            'handled_by' => null,
            'handled_at' => null,
        ]);

        $this->dispatch('toast', message: __('admin.cb_reopened', ['name' => $request->name]));
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

        if ($this->openCallback === $id) {
            $this->openCallback = null;
        }

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

        if ($this->openCallback === $id) {
            $this->openCallback = null;
        }

        $this->dispatch('toast', message: __('admin.cb_marked_closed', ['name' => $request->name]));
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
        $this->reset(['search', 'status', 'payment', 'paid', 'from', 'to', 'callState']);
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
