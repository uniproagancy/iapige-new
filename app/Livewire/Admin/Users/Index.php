<?php

namespace App\Livewire\Admin\Users;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
                'admins' => User::withRole(User::ADMIN)->count(),
                'operators' => User::withRole(User::OPERATOR)->count(),
                'customers' => User::withRole(User::CUSTOMER)->count(),
                'subscribed' => User::where('accepts_marketing', true)->count(),
            ],
            'canSetRole' => Gate::allows('owner'),
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
            ->when($this->filter === 'admins', fn ($q) => $q->withRole(User::ADMIN))
            ->when($this->filter === 'operators', fn ($q) => $q->withRole(User::OPERATOR))
            ->when($this->filter === 'customers', fn ($q) => $q->withRole(User::CUSTOMER))
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

    public function setRole(int $id, string $role): void
    {
        if (! Gate::allows('owner')) {
            $this->dispatch('toast', message: __('admin.role_denied'), type: 'error');

            return;
        }

        if (! in_array($role, User::roles(), true)) {
            return;
        }

        /* Letting the last owner step down would lock everyone out. */
        if ($id === auth()->id()) {
            $this->dispatch('toast', message: __('admin.cannot_change_own_role'), type: 'error');

            return;
        }

        $user = User::findOrFail($id);

        $user->forceFill([
            'role' => $role,
            'is_admin' => $role === User::ADMIN,
        ])->save();

        $this->dispatch('toast', message: __('admin.role_changed', [
            'name' => $user->firstName(),
            'role' => __('admin.'.($role === User::CUSTOMER ? 'customer_role' : $role)),
        ]));
    }
}
