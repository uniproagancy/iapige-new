@php
    $tone = [
        'new' => 'pend', 'viewed' => 'way',
        'completed' => 'done', 'cancelled' => 'off',
    ];

    $initials = collect(explode(' ', trim($user->name)))
        ->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp

<div class="page">
    <nav class="crumbs" aria-label="{{ __('common.breadcrumbs') }}">
        <a href="{{ route('home') }}">{{ __('common.home') }}</a>
        <span aria-hidden="true">/</span>
        <span>{{ __('account.title') }}</span>
    </nav>

    <header class="acc-head">
        <span class="acc-avatar" aria-hidden="true">{{ $initials ?: '•' }}</span>

        <div class="acc-head__body">
            <h1 class="acc-head__name">{{ $user->name }}</h1>
            <span class="acc-head__meta">{{ $user->email }}@if ($user->phone) · {{ $user->phone }} @endif</span>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="pill">{{ __('account.sign_out') }}</button>
        </form>
    </header>

    <div class="acc">

        <aside class="acc__nav">
            @foreach ([
                'overview'  => __('account.overview'),
                'orders'    => __('account.orders'),
                'addresses' => __('account.addresses'),
                'wishlist'  => __('account.wishlist'),
                'profile'   => __('account.profile'),
                'security'  => __('account.security'),
            ] as $key => $label)
                <button type="button" @class(['acc__tab', 'is-on' => $tab === $key])
                        wire:click="$set('tab', '{{ $key }}')">
                    <span>{{ $label }}</span>
                    @if ($key === 'orders' && $stats['active'])
                        <span class="acc__badge">{{ $stats['active'] }}</span>
                    @elseif ($key === 'wishlist' && count($wishlist))
                        <span class="acc__badge acc__badge--soft">{{ count($wishlist) }}</span>
                    @endif
                </button>
            @endforeach
        </aside>

        <div class="acc__body">

            @if ($tab === 'overview')
                <div class="acc-stats">
                    <div class="acc-stat">
                        <span class="acc-stat__n">{{ $stats['orders'] }}</span>
                        <span class="acc-stat__l">{{ __('account.orders_total') }}</span>
                    </div>
                    <div class="acc-stat">
                        <span class="acc-stat__n">{{ $stats['active'] }}</span>
                        <span class="acc-stat__l">{{ __('account.orders_active') }}</span>
                    </div>
                    <div class="acc-stat">
                        <span class="acc-stat__n">{{ money($stats['spent']) }}</span>
                        <span class="acc-stat__l">{{ __('account.spent') }}</span>
                    </div>
                </div>

                @if ($active)
                    <section class="track">
                        <div class="track__head">
                            <div>
                                <span class="track__eyebrow">{{ __('account.current_order') }}</span>
                                <span class="track__num">#{{ $active->number }}</span>
                            </div>
                            <span class="ord__state ord__state--{{ $tone[$active->status] ?? 'off' }}">
                                {{ __('order.status.'.$active->status) }}
                            </span>
                        </div>

                        <div class="track__foot">
                            <span>{{ trans_choice('account.n_items', $active->items->sum('qty'), ['count' => $active->items->sum('qty')]) }}</span>
                            <span>{{ money($active->total) }}</span>
                            @if ($active->city)
                                <span>{{ $active->city }}, {{ $active->address }}</span>
                            @endif
                        </div>
                    </section>
                @else
                    <div class="acc__empty">
                        <p>{{ __('account.nothing_on_the_way') }}</p>
                        <a class="btn-main" href="{{ route('catalog') }}">{{ __('account.start_shopping') }}</a>
                    </div>
                @endif

                <div class="acc-quick">
                    <button type="button" class="acc-quick__item" wire:click="$set('tab', 'orders')">
                        <span class="acc-quick__t">{{ __('account.orders') }}</span>
                        <span class="acc-quick__s">{{ __('account.orders_hint') }}</span>
                    </button>

                    <button type="button" class="acc-quick__item" wire:click="$set('tab', 'addresses')">
                        <span class="acc-quick__t">{{ __('account.addresses') }}</span>
                        <span class="acc-quick__s">{{ trans_choice('account.n_addresses', $addresses->count(), ['count' => $addresses->count()]) }}</span>
                    </button>

                    <button type="button" class="acc-quick__item" wire:click="$set('tab', 'wishlist')">
                        <span class="acc-quick__t">{{ __('account.wishlist') }}</span>
                        <span class="acc-quick__s">{{ trans_choice('account.n_saved', count($wishlist), ['count' => count($wishlist)]) }}</span>
                    </button>
                </div>
            @endif

            @if ($tab === 'orders')
                <div class="acc__filters">
                    @foreach ([
                        ''          => __('account.all'),
                        'active'    => __('account.in_progress'),
                        'completed' => __('order.status.completed'),
                        'cancelled' => __('order.status.cancelled'),
                    ] as $key => $label)
                        <button type="button" @class(['chip', 'is-on' => $orderFilter === $key])
                                wire:click="$set('orderFilter', '{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </div>

                @forelse ($orders as $order)
                    <div class="ord" wire:key="o-{{ $order->id }}">
                        <button type="button" class="ord__head" wire:click="toggleOrder({{ $order->id }})">
                            <span class="ord__num">#{{ $order->number }}</span>
                            <span class="ord__date">{{ $order->created_at->translatedFormat('j F Y') }}</span>
                            <span class="ord__state ord__state--{{ $tone[$order->status] ?? 'off' }}">
                                {{ __('order.status.'.$order->status) }}
                            </span>
                            <span class="ord__sum">{{ money($order->total) }}</span>
                        </button>

                        @if ($openOrder === $order->id)
                            <div class="ord__body">
                                @foreach ($order->items as $item)
                                    <div class="ord__line">
                                        <span class="ord__name">{{ $item->name }}</span>
                                        <span class="ord__qty">x {{ $item->qty }}</span>
                                        <span class="ord__price">{{ money($item->price * $item->qty) }}</span>
                                    </div>
                                @endforeach

                                <div class="ord__meta">
                                    <span>{{ __('checkout.shipping') }}: {{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}</span>
                                    @if ($order->city)
                                        <span>{{ $order->city }}, {{ $order->address }}</span>
                                    @endif
                                    <span>{{ $order->is_paid ? __('account.paid') : __('account.unpaid') }}</span>
                                </div>

                            </div>
                        @endif
                    </div>
                @empty
                    <div class="acc__empty">
                        <p>{{ __('account.no_orders') }}</p>
                        <a class="btn-main" href="{{ route('catalog') }}">{{ __('account.start_shopping') }}</a>
                    </div>
                @endforelse

                @if ($hasMore)
                    <button type="button" class="btn-ghost acc__more" wire:click="loadMore">
                        {{ __('common.show_more') }}
                    </button>
                @endif
            @endif

            @if ($tab === 'addresses')
                <div class="acc__toolbar">
                    <button type="button" class="btn-main" wire:click="newAddress">{{ __('account.add_address') }}</button>
                </div>

                @forelse ($addresses as $address)
                    <div class="addr" wire:key="a-{{ $address->id }}">
                        <div class="addr__body">
                            <span class="addr__label">
                                {{ $address->label ?: $address->city?->name }}
                                @if ($address->is_default)
                                    <span class="addr__flag">{{ __('account.default') }}</span>
                                @endif
                            </span>
                            <span class="addr__text">{{ $address->oneLine() }}</span>
                            <span class="addr__who">{{ $address->name }} · {{ $address->phone }}</span>
                        </div>

                        <div class="addr__acts">
                            @unless ($address->is_default)
                                <button type="button" class="pill" wire:click="makeDefaultAddress({{ $address->id }})">
                                    {{ __('account.make_default') }}
                                </button>
                            @endunless

                            <button type="button" class="pill" wire:click="editAddress({{ $address->id }})">
                                {{ __('common.edit') }}
                            </button>

                            <button type="button" class="pill pill--danger"
                                    wire:click="deleteAddress({{ $address->id }})"
                                    wire:confirm="{{ __('account.delete_address_confirm') }}">
                                {{ __('common.delete') }}
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="acc__empty">
                        <p>{{ __('account.no_addresses') }}</p>
                    </div>
                @endforelse

                @if ($showAddress)
                    <form class="acc__form" wire:submit="saveAddress">
                        <h2 class="acc__h2">{{ $addressId ? __('common.edit') : __('account.add_address') }}</h2>

                        <div class="acc__grid">
                            <div class="field">
                                <label class="field__label">{{ __('account.address_label') }}</label>
                                <input type="text" class="field__input" placeholder="{{ __('account.address_label_ph') }}"
                                       wire:model="a_label">
                            </div>

                            <div class="field">
                                <label class="field__label">{{ __('checkout.city') }}</label>
                                <select class="field__input" wire:model="city_id">
                                    <option value="">—</option>
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </select>
                                @error('city_id') <span class="field__err">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="field">
                            <label class="field__label">{{ __('checkout.address') }}</label>
                            <input type="text" class="field__input" wire:model="a_address">
                            @error('a_address') <span class="field__err">{{ $message }}</span> @enderror
                        </div>

                        <div class="acc__grid">
                            <div class="field">
                                <label class="field__label">{{ __('checkout.name') }}</label>
                                <input type="text" class="field__input" wire:model="a_name">
                                @error('a_name') <span class="field__err">{{ $message }}</span> @enderror
                            </div>

                            <div class="field">
                                <label class="field__label">{{ __('checkout.phone') }}</label>
                                <input type="tel" class="field__input" wire:model="a_phone">
                                @error('a_phone') <span class="field__err">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="field">
                            <label class="field__label">{{ __('account.address_note') }}</label>
                            <input type="text" class="field__input" placeholder="{{ __('account.address_note_ph') }}"
                                   wire:model="a_note">
                        </div>

                        <label class="check">
                            <input type="checkbox" wire:model="a_default">
                            <span>{{ __('account.set_default') }}</span>
                        </label>

                        <div class="acc__formActs">
                            <button type="button" class="pill" wire:click="$set('showAddress', false)">
                                {{ __('common.cancel') }}
                            </button>
                            <button type="submit" class="btn-main">{{ __('common.save') }}</button>
                        </div>
                    </form>
                @endif
            @endif

            @if ($tab === 'wishlist')
                @if ($wishlist)
                    <div class="grid">
                        @foreach ($wishlist as $card)
                            <x-product-card :p="$card" :key="'w-'.$card['id']" />
                        @endforeach
                    </div>
                @else
                    <div class="acc__empty">
                        <p>{{ __('account.no_wishlist') }}</p>
                        <a class="btn-main" href="{{ route('catalog') }}">{{ __('account.start_shopping') }}</a>
                    </div>
                @endif
            @endif

            @if ($tab === 'profile')
                <form class="acc__form" wire:submit="saveProfile">
                    <h2 class="acc__h2">{{ __('account.personal_data') }}</h2>

                    <div class="acc__grid">
                        <div class="field">
                            <label class="field__label">{{ __('checkout.name') }}</label>
                            <input type="text" class="field__input" wire:model="name">
                            @error('name') <span class="field__err">{{ $message }}</span> @enderror
                        </div>

                        <div class="field">
                            <label class="field__label">{{ __('checkout.phone') }}</label>
                            <input type="tel" class="field__input" wire:model="phone">
                            @error('phone') <span class="field__err">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label">{{ __('checkout.email') }}</label>
                        <input type="email" class="field__input" wire:model="email">
                        @error('email') <span class="field__err">{{ $message }}</span> @enderror
                    </div>

                    <label class="check">
                        <input type="checkbox" wire:model="accepts_marketing">
                        <span>{{ __('account.accepts_marketing') }}</span>
                    </label>

                    <div class="acc__formActs">
                        <button type="submit" class="btn-main">{{ __('common.save') }}</button>
                    </div>
                </form>
            @endif

            @if ($tab === 'security')
                <form class="acc__form" wire:submit="changePassword">
                    <h2 class="acc__h2">{{ __('account.change_password') }}</h2>

                    <div class="field">
                        <label class="field__label">{{ __('account.current_password') }}</label>
                        <input type="password" class="field__input" wire:model="current_password" autocomplete="current-password">
                        @error('current_password') <span class="field__err">{{ $message }}</span> @enderror
                    </div>

                    <div class="acc__grid">
                        <div class="field">
                            <label class="field__label">{{ __('account.new_password') }}</label>
                            <input type="password" class="field__input" wire:model="password" autocomplete="new-password">
                            @error('password') <span class="field__err">{{ $message }}</span> @enderror
                        </div>

                        <div class="field">
                            <label class="field__label">{{ __('account.repeat_password') }}</label>
                            <input type="password" class="field__input" wire:model="password_confirmation" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="acc__formActs">
                        <button type="submit" class="btn-main">{{ __('common.save') }}</button>
                    </div>
                </form>

                <div class="acc__form">
                    <h2 class="acc__h2">{{ __('account.other_devices') }}</h2>
                    <p class="acc__note">{{ __('account.other_devices_hint') }}</p>

                    <div class="field">
                        <label class="field__label">{{ __('account.current_password') }}</label>
                        <input type="password" class="field__input" wire:model="session_password">
                        @error('session_password') <span class="field__err">{{ $message }}</span> @enderror
                    </div>

                    <div class="acc__formActs">
                        <button type="button" class="pill" wire:click="logoutOtherDevices">
                            {{ __('account.close_other_sessions') }}
                        </button>
                    </div>
                </div>

                <div class="acc__form acc__form--danger">
                    <h2 class="acc__h2">{{ __('account.close_account') }}</h2>
                    <p class="acc__note">{{ __('account.close_account_hint') }}</p>

                    @if (! $confirmDelete)
                        <div class="acc__formActs">
                            <button type="button" class="pill pill--danger" wire:click="$set('confirmDelete', true)">
                                {{ __('account.close_account') }}
                            </button>
                        </div>
                    @else
                        <div class="field">
                            <label class="field__label">{{ __('account.current_password') }}</label>
                            <input type="password" class="field__input" wire:model="delete_password">
                            @error('delete_password') <span class="field__err">{{ $message }}</span> @enderror
                        </div>

                        <div class="acc__formActs">
                            <button type="button" class="pill" wire:click="$set('confirmDelete', false)">
                                {{ __('common.cancel') }}
                            </button>

                            <button type="button" class="btn-main btn-main--danger"
                                    wire:click="deleteAccount"
                                    wire:confirm="{{ __('account.close_account_confirm') }}">
                                {{ __('account.close_account_yes') }}
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
