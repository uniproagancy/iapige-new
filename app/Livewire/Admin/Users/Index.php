<?php

namespace App\Livewire\Admin\Users;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'f', except: '')]
    public string $filter = '';

    #[Url(as: 'orders', except: '')]
    public string $hasOrders = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'sort', except: 'new')]
    public string $sort = 'new';

    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    protected array $filterable = ['search', 'filter', 'hasOrders', 'from', 'to', 'sort', 'perPage'];

    public function updated($property): void
    {
        if (in_array($property, $this->filterable, true)) {
            $this->resetPage();
        }
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->filter !== ''
            || $this->hasOrders !== ''
            || $this->from !== ''
            || $this->to !== '';
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filter', 'hasOrders', 'from', 'to']);
        $this->resetPage();
    }

    public function render()
    {
        $totals = $this->query()
            ->reorder()
            ->select(DB::raw('count(*) as people, coalesce(sum(coalesce(stats.spent, 0)), 0) as spent'))
            ->first();

        return view('livewire.admin.users.index', [
            'users' => $this->query()->paginate($this->perPage),
            'totals' => [
                'people' => (int) ($totals->people ?? 0),
                'spent' => (float) ($totals->spent ?? 0),
            ],
            'counts' => [
                'total' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
                'customers' => User::where('is_admin', false)->count(),
                'subscribed' => User::where('accepts_marketing', true)->count(),
            ],
        ])->layout('layouts.admin', ['title' => __('admin.customers')]);
    }

    protected function query()
    {
        return User::query()

            ->leftJoinSub(
                Order::selectRaw('user_id, count(*) as orders_count, coalesce(sum(case when status = ? then total else 0 end), 0) as spent', ['completed'])
                    ->whereNotNull('user_id')
                    ->groupBy('user_id'),
                'stats',
                'stats.user_id',
                '=',
                'users.id',
            )
            ->select('users.*', DB::raw('coalesce(stats.orders_count, 0) as orders_count'), DB::raw('coalesce(stats.spent, 0) as spent'))
            ->when($this->search, function ($q) {
                $term = trim($this->search);
                $digits = preg_replace('/\D/', '', $term);

                $q->where(fn ($w) => $w
                    ->where('users.name', 'like', "%{$term}%")
                    ->orWhere('users.email', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($p) => $p->orWhere('users.phone', 'like', "%{$digits}%")));
            })
            ->when($this->filter === 'admins', fn ($q) => $q->where('users.is_admin', true))
            ->when($this->filter === 'customers', fn ($q) => $q->where('users.is_admin', false))
            ->when($this->filter === 'subscribed', fn ($q) => $q->where('users.accepts_marketing', true))
            ->when($this->hasOrders === 'yes', fn ($q) => $q->whereNotNull('stats.user_id'))
            ->when($this->hasOrders === 'no', fn ($q) => $q->whereNull('stats.user_id'))
            ->when($this->from !== '', fn ($q) => $q->whereDate('users.created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('users.created_at', '<=', $this->to))
            ->when($this->sort === 'spent', fn ($q) => $q->orderByDesc('spent'))
            ->when($this->sort === 'orders', fn ($q) => $q->orderByDesc('orders_count'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('users.name'))
            ->when($this->sort === 'new', fn ($q) => $q->orderByDesc('users.id'));
    }

    public function toggleAdmin(int $id): void
    {
        if ($id === auth()->id()) {
            $this->dispatch('toast', message: __('admin.cannot_demote_self'), type: 'error');

            return;
        }

        $user = User::findOrFail($id);
        $user->update(['is_admin' => ! $user->is_admin]);

        $this->dispatch('toast', message: __('admin.saved'));
    }
}
