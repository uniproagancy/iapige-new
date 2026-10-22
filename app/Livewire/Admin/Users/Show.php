<?php

namespace App\Livewire\Admin\Users;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public User $user;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $accepts_marketing = false;

    public function mount(User $user): void
    {
        $this->user = $user;

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->accepts_marketing = (bool) $user->accepts_marketing;
    }

    public function render()
    {
        return view('livewire.admin.users.show', [
            'orders' => Order::where('user_id', $this->user->id)
                ->with('items')
                ->latest('id')
                ->take(50)
                ->get(),
            'addresses' => UserAddress::where('user_id', $this->user->id)->with('city')->get(),
            'callbacks' => CallbackRequest::where('user_id', $this->user->id)
                ->orWhere('phone', $this->user->phone)
                ->latest('id')
                ->take(20)
                ->get(),
            'stats' => $this->stats(),
        ])->layout('layouts.admin', ['title' => $this->user->name]);
    }

    protected function stats(): array
    {
        $row = DB::table('orders')
            ->where('user_id', $this->user->id)
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('new','viewed') then 1 else 0 end) as active,
                coalesce(sum(case when status = 'completed' then total else 0 end), 0) as spent,
                max(created_at) as last_order
            ")
            ->first();

        return [
            'orders' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'spent' => (float) ($row->spent ?? 0),
            'last' => $row->last_order ?? null,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $this->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'accepts_marketing' => $this->accepts_marketing,
        ]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function anonymise(): void
    {
        if ($this->user->isStaff()) {
            $this->dispatch('toast', message: __('admin.cannot_delete_admin'), type: 'error');

            return;
        }

        DB::transaction(function () {
            UserAddress::where('user_id', $this->user->id)->delete();

            $this->user->update([
                'name' => __('account.deleted_user'),
                'email' => 'deleted-'.$this->user->id.'@iapi.local',
                'phone' => null,
                'accepts_marketing' => false,
            ]);

            $this->user->delete();
        });

        $this->redirect(route('admin.users'), navigate: true);
    }
}
