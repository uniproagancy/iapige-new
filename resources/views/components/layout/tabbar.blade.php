@props(['active' => 'home'])

@php $count = app(\App\Services\Cart::class)->count(); @endphp

<nav class="tabbar" aria-label="{{ __('nav.tabbar') }}">
    <a href="{{ route('home') }}" @class(['tab', 'is-active' => $active === 'home'])>
        <span class="tab__ico"><x-icon :name="$active === 'home' ? 'house-fill' : 'house'" size="22" /></span>
        <span class="tab__label">{{ __('nav.tab_home') }}</span>
    </a>
    <button type="button" @class(['tab', 'is-active' => $active === 'catalog']) data-open="catalog">
        <span class="tab__ico"><x-icon :name="$active === 'catalog' ? 'squares-four-fill' : 'squares-four'" size="22" /></span>
        <span class="tab__label">{{ __('nav.tab_catalog') }}</span>
    </button>
    <button type="button" class="tab" data-search-tab>
        <span class="tab__ico"><x-icon name="magnifying-glass" size="22" /></span>
        <span class="tab__label">{{ __('nav.tab_search') }}</span>
    </button>
    <button type="button" class="tab" data-open="cart">
        <span class="tab__ico"><x-icon name="shopping-bag" size="22" /><span class="tab__badge" id="tabBadge" @if(! $count) hidden @endif>{{ $count }}</span></span>
        <span class="tab__label">{{ __('nav.tab_cart') }}</span>
    </button>
    @auth
		<a class="tabbar__item" href="{{ route('account') }}">
			<x-icon name="user" size="20" />
			<span>{{ __('account.title') }}</span>
		</a>
	@else
		<button type="button" class="tabbar__item" data-open="auth">
			<x-icon name="user" size="20" />
			<span>{{ __('auth.sign_in') }}</span>
		</button>
	@endauth
</nav>
