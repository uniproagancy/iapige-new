@props(['active' => 'home'])

@php
    $categories = \App\Support\Catalog::menu();
@endphp

<nav class="nav" aria-label="{{ __('nav.main') }}">
    <div class="nav__main">
        <a href="{{ route('home') }}" @class(['is-active' => $active === 'home'])>{{ __('nav.home') }}</a>

        @foreach ($categories as $category)
            <a href="{{ $category['url'] }}"
               @class(['is-active' => $active === 'catalog' && request()->is(trim(parse_url($category['url'], PHP_URL_PATH), '/').'*')])>{{ $category['name'] }}</a>
        @endforeach

        <a href="{{ route('catalog') }}" @class(['is-active' => $active === 'catalog' && request()->routeIs('catalog') && ! request()->route('slug')])>{{ __('nav.products') }}</a>
        <a href="{{ route('about') }}" @class(['is-active' => $active === 'about'])>{{ __('nav.about') }}</a>
        <a href="{{ route('contact') }}" @class(['is-active' => $active === 'contact'])>{{ __('nav.contact') }}</a>
    </div>

    <div class="nav__side">
        <a href="{{ route('home') }}#promo" class="is-hot">{{ __('nav.deals') }}</a>
    </div>
</nav>