<?php

namespace App\Livewire\Account;

use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything a customer has with us, as one component with tabs.
 *
 * It opens on an overview rather than a list, because the question people
 * actually arrive with is "where is my order" — not "show me everything I have
 * ever bought".
 */
class Index extends Component
{
    /** The steps an order walks through, as the customer sees them. */
    public const TRACK = ['new', 'confirmed', 'packed', 'shipped', 'completed'];

    /** overview | orders | addresses | wishlist | profile | security */
    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    /** '' | active | completed | cancelled */
    #[Url(as: 'f', except: '')]
    public string $orderFilter = '';

    public int $perPage = 10;

    /* ---- profile ---- */
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public bool $accepts_marketing = false;

    /* ---- password ---- */
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /* ---- sessions / deletion ---- */
    public string $session_password = '';
    public string $delete_password = '';
    public bool $confirmDelete = false;

    /* ---- address form ---- */
    public bool $showAddress = false;
    public ?int $addressId = null;
    public ?int $city_id = null;
    public string $a_label = '';
    public string $a_name = '';
    public string $a_phone = '';
    public string $a_address = '';
    public string $a_note = '';
    public bool $a_default = false;

    /** the order opened in place, so the list keeps its scroll */
    public ?int $openOrder = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = (string) $user->name;
        $this->phone = (string) $user->phone;
        $this->email = (string) $user->email;
        $this->accepts_marketing = (bool) $user->accepts_marketing;
    }

    public function updated($property): void
    {
        if ($property === 'orderFilter') {
            $this->perPage = 10;
            $this->openOrder = null;
        }
    }

    /* ------------------------------------------------------------------ render */

    public function render()
    {
        $user = auth()->user();

        return view('livewire.account.index', [
            'user'      => $user,
            'active'    => $this->activeOrder(),
            'orders'    => $this->orders(),
            'hasMore'   => $this->ordersQuery()->count() > $this->perPage,
            'addresses' => UserAddress::where('user_id', $user->id)->with('city')
                ->orderByDesc('is_default')->get(),
            'wishlist'  => $this->wishlist(),
            'cities'    => DeliveryCity::active()->withTranslation()->orderBy('sort_order')->get(),
            'stats'     => $this->stats($user->id),
        ])->layout('layouts.app', ['title' => __('account.title')]);
    }

    /** The one order a customer is actually waiting on. */
    protected function activeOrder(): ?Order
    {
        return Order::where('user_id', auth()->id())
            ->whereIn('status', ['new', 'confirmed', 'packed', 'shipped'])
            ->with('items')
            ->latest('id')
            ->first();
    }

    protected function ordersQuery()
    {
        return Order::where('user_id', auth()->id())
            ->when($this->orderFilter === 'active', fn ($q) => $q->whereIn('status', ['new', 'confirmed', 'packed', 'shipped']))
            ->when($this->orderFilter === 'completed', fn ($q) => $q->where('status', 'completed'))
            ->when($this->orderFilter === 'cancelled', fn ($q) => $q->whereIn('status', ['cancelled', 'returned']));
    }

    protected function orders()
    {
        return $this->ordersQuery()
            ->with('items')
            ->latest('id')
            ->take($this->perPage)
            ->get();
    }

    public function loadMore(): void
    {
        $this->perPage += 10;
    }

    /** @return array<int, array> */
    protected function wishlist(): array
    {
        $ids = method_exists(auth()->user(), 'wishlist')
            ? auth()->user()->wishlist()->pluck('product_id')
            : collect();

        if ($ids->isEmpty()) {
            return [];
        }

        return \App\Support\Catalog::cards(
            \App\Support\Catalog::productQuery()->whereIn('id', $ids)->get()
        );
    }

    /** The three numbers a customer recognises about themselves. */
    protected function stats(int $userId): array
    {
        $row = DB::table('orders')
            ->where('user_id', $userId)
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('new','confirmed','packed','shipped') then 1 else 0 end) as active,
                coalesce(sum(case when status = 'completed' then total else 0 end), 0) as spent
            ")
            ->first();

        return [
            'orders' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'spent'  => (float) ($row->spent ?? 0),
        ];
    }

    /** Where an order stands, as a position in TRACK; -1 when it ended badly. */
    public function trackStep(Order $order): int
    {
        $position = array_search($order->status, self::TRACK, true);

        return $position === false ? -1 : $position;
    }

    /* ------------------------------------------------------------------ orders */

    public function toggleOrder(int $id): void
    {
        $this->openOrder = $this->openOrder === $id ? null : $id;
    }

    /** Puts a past order back in the cart, skipping what is no longer sold. */
    public function reorder(int $id): void
    {
        $order = Order::where('user_id', auth()->id())->with('items')->findOrFail($id);

        $added = 0;
        $skipped = 0;

        foreach ($order->items as $item) {
            $product = Product::active()->whereKey($item->product_id)->first();

            if (! $product || $product->stock < 1) {
                $skipped++;

                continue;
            }

            $this->dispatch('cart-add', id: $product->id, qty: (int) $item->qty);
            $added++;
        }

        $this->dispatch('toast', message: $skipped
            ? __('account.reordered_partly', ['added' => $added, 'skipped' => $skipped])
            : __('account.reordered', ['count' => $added]));
    }

    /**
     * A customer may call off an order we have not packed yet. After that it is
     * a conversation, not a button, so the option disappears.
     */
    public function cancelOrder(int $id): void
    {
        $order = Order::where('user_id', auth()->id())->findOrFail($id);

        if (! in_array($order->status, ['new', 'confirmed'], true)) {
            $this->dispatch('toast', message: __('account.cancel_too_late'), type: 'error');

            return;
        }

        DB::transaction(function () use ($order) {
            $from = $order->status;

            $order->update(['status' => 'cancelled']);

            foreach ($order->items as $item) {
                Product::whereKey($item->product_id)->increment('stock', (int) $item->qty);
            }

            $order->events()->create([
                'user_id' => auth()->id(),
                'type'    => 'status',
                'from'    => $from,
                'to'      => 'cancelled',
                'note'    => __('account.cancelled_by_customer'),
            ]);
        });

        $this->dispatch('toast', message: __('account.order_cancelled'));
    }

    /* ------------------------------------------------------------------ addresses */

    public function newAddress(): void
    {
        $this->resetAddress();
        $this->a_name = $this->name;
        $this->a_phone = $this->phone;
        $this->showAddress = true;
    }

    public function editAddress(int $id): void
    {
        $address = UserAddress::where('user_id', auth()->id())->findOrFail($id);

        $this->addressId = $address->id;
        $this->city_id = $address->city_id;
        $this->a_label = (string) $address->label;
        $this->a_name = (string) $address->name;
        $this->a_phone = (string) $address->phone;
        $this->a_address = (string) $address->address;
        $this->a_note = (string) $address->note;
        $this->a_default = (bool) $address->is_default;
        $this->resetValidation();

        $this->showAddress = true;
    }

    public function saveAddress(): void
    {
        $this->validate([
            'city_id'   => ['required', 'exists:delivery_cities,id'],
            'a_name'    => ['required', 'string', 'max:120'],
            'a_phone'   => ['required', 'string', 'max:32'],
            'a_address' => ['required', 'string', 'max:255'],
            'a_label'   => ['nullable', 'string', 'max:40'],
        ]);

        $address = UserAddress::updateOrCreate(
            ['id' => $this->addressId, 'user_id' => auth()->id()],
            [
                'city_id' => $this->city_id,
                'label'   => $this->a_label ?: null,
                'name'    => $this->a_name,
                'phone'   => $this->a_phone,
                'address' => $this->a_address,
                'note'    => $this->a_note ?: null,
            ],
        );

        // the first address a customer saves is the one checkout should use
        if ($this->a_default || UserAddress::where('user_id', auth()->id())->count() === 1) {
            $address->makeDefault();
        }

        $this->showAddress = false;
        $this->dispatch('toast', message: __('account.saved'));
    }

    public function makeDefaultAddress(int $id): void
    {
        UserAddress::where('user_id', auth()->id())->findOrFail($id)->makeDefault();
    }

    public function deleteAddress(int $id): void
    {
        UserAddress::where('user_id', auth()->id())->findOrFail($id)->delete();
        $this->dispatch('toast', message: __('account.deleted'));
    }

    protected function resetAddress(): void
    {
        $this->addressId = null;
        $this->city_id = null;
        $this->a_label = '';
        $this->a_name = '';
        $this->a_phone = '';
        $this->a_address = '';
        $this->a_note = '';
        $this->a_default = false;
        $this->resetValidation();
    }

    /* ------------------------------------------------------------------ profile */

    public function saveProfile(): void
    {
        $user = auth()->user();

        $this->validate([
            'name'  => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update([
            'name'              => $this->name,
            'phone'             => $this->phone,
            'email'             => $this->email,
            'accepts_marketing' => $this->accepts_marketing,
        ]);

        $this->dispatch('toast', message: __('account.saved'));
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($this->current_password, auth()->user()->password)) {
            $this->addError('current_password', __('account.wrong_password'));

            return;
        }

        auth()->user()->update(['password' => Hash::make($this->password)]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->dispatch('toast', message: __('account.password_changed'));
    }

    /** Signs out everywhere else; this session stays. */
    public function logoutOtherDevices(): void
    {
        $this->validate(['session_password' => ['required']]);

        if (! Hash::check($this->session_password, auth()->user()->password)) {
            $this->addError('session_password', __('account.wrong_password'));

            return;
        }

        auth()->logoutOtherDevices($this->session_password);

        $this->reset('session_password');
        $this->dispatch('toast', message: __('account.other_sessions_closed'));
    }

    /**
     * Closing an account keeps the orders: they are our accounting record as
     * much as the customer's history, so the person is anonymised instead.
     */
    public function deleteAccount()
    {
        $this->validate(['delete_password' => ['required']]);

        $user = auth()->user();

        if (! Hash::check($this->delete_password, $user->password)) {
            $this->addError('delete_password', __('account.wrong_password'));

            return null;
        }

        DB::transaction(function () use ($user) {
            UserAddress::where('user_id', $user->id)->delete();

            $user->update([
                'name'              => __('account.deleted_user'),
                'email'             => 'deleted-'.$user->id.'@elio.local',
                'phone'             => null,
                'accepts_marketing' => false,
                'password'          => Hash::make(str()->random(40)),
            ]);

            $user->delete();
        });

        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('home');
    }
}
