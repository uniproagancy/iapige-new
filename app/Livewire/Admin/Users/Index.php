<?php

namespace App\Livewire\Admin\Users;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The customer list.
 *
 * Sorted by what they have spent rather than when they registered, because the
 * question this screen answers is usually "who is this person on the phone"
 * or "who is worth calling back".
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' | customers | admins | subscribed */
    #[Url(as: 'f', except: '')]
    public string $filter = '';

    /** spent | orders | new | name */
    #[Url(as: 'sort', except: 'new')]
    public string $sort = 'new';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'filter', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        return view('livewire.admin.users.index', [
            'users'  => $this->query()->paginate(25),
            'counts' => [
                'total'      => User::count(),
                'admins'     => User::where('is_admin', true)->count(),
                'subscribed' => User::where('accepts_marketing', true)->count(),
            ],
        ])->layout('layouts.admin', ['title' => __('admin.customers')]);
    }

    protected function query()
    {
        return User::query()
            /*
             * Totals come from a join rather than a count per row: a hundred
             * customers would otherwise be two hundred queries.
             */
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
            ->when($this->sort === 'spent', fn ($q) => $q->orderByDesc('spent'))
            ->when($this->sort === 'orders', fn ($q) => $q->orderByDesc('orders_count'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('users.name'))
            ->when($this->sort === 'new', fn ($q) => $q->orderByDesc('users.id'));
    }

    /**
     * Grants or removes admin rights.
     *
     * A person cannot remove their own, because an empty admin list locks
     * everybody out of the shop.
     */
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
