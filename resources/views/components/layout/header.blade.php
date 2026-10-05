@php $cart = app(\App\Services\Cart::class)->summary(); @endphp

<div class="head-sentinel" id="headSentinel" aria-hidden="true"></div>
<header class="header" id="header">
    <a class="logo" href="{{ route('home') }}" aria-label="{{ __('layout.logo_label') }}">
        <span class="logo__mark" aria-hidden="true"><img src="{{ asset('/img/logo.png') }}"></span>
        <span>
            <span class="logo__name">იაფი</span>
            <span class="logo__sub">IAPI.GE</span>
        </span>
    </a>

    <button type="button" class="btn-catalog" id="catalogBtn" aria-expanded="false" aria-controls="catalog">
        <x-icon name="list" size="18" class="ic-burger" />
		<x-icon name="x" size="18" class="ic-close" />
        {{ __('layout.catalog') }}
    </button>

    <livewire:header-search />

    <div class="actions">
        @auth
			<a class="icon-btn" href="{{ route('account') }}" title="{{ __('account.title') }}">
				<x-icon name="user" size="18" />

				@php
					$pending = \App\Models\Order::where('user_id', auth()->id())
						->whereIn('status', ['new', 'confirmed', 'packed', 'shipped'])
						->count();
				@endphp

				@if ($pending)
					<span class="icon-btn__dot" aria-label="{{ __('account.orders_active') }}">{{ $pending }}</span>
				@endif
			</a>
		@else
			<button type="button" class="icon-btn" data-open="auth" title="{{ __('auth.sign_in') }}">
				<x-icon name="user" size="18" />
			</button>
		@endauth
        <button type="button" class="btn-cart" id="cartBtn" aria-controls="cart">
            <span class="btn-cart__icon">
                <x-icon name="shopping-bag" size="18" />
                <span class="btn-cart__badge" id="cartBadge">{{ $cart['count'] }}</span>
            </span>
            <span class="btn-cart__meta">
                <span class="btn-cart__label">{{ __('layout.cart') }}</span>
				<span class="btn-cart__total" id="cartTotalTop">{{ app(App\Services\Cart::class)->summary()['total'] }}</span>
            </span>
        </button>
    </div>
</header>
