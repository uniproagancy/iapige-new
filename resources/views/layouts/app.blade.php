@php
    use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

    $i18n = [
        'results'      => __('layout.results'),
        'allResults'   => __('layout.all_results'),
        'noResults'    => __('layout.no_results'),
        'added'        => __('cart.added'),
        'checkout'     => __('cart.checkout').' — '.__('common.demo'),
        'emailInvalid' => __('common.email_invalid'),
        'phoneInvalid' => __('common.phone_invalid'),
        'nameInvalid'  => __('common.name_invalid'),
        'subscribed'   => __('footer.subscribed'),
        'cookiesAll'   => __('cookies.accepted_all'),
        'cookiesNeed'  => __('cookies.accepted_necessary'),
        'loggedIn'     => __('auth.logged_in'),
        'registered'   => __('auth.registered'),
        'show'         => __('auth.show'),
        'hide'         => __('auth.hide'),
        'more'         => __('catalog.more'),
        'productAdded' => __('product.added'),
        'productAdd'   => __('product.add'),
        'bundleAdded'  => __('product.bundle_added'),
        'buyNow'       => __('product.buy_now_demo'),
        'callSent'     => __('product.call_sent'),
        'callMe'       => __('product.call_me'),
        'consultSent'  => __('product.consult_sent'),
        'sent'         => __('info.sent'),
        'send'         => __('info.send'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('layout.title'))</title>
    <meta name="description" content="@yield('description', __('layout.description'))">
    <meta name="theme-color" content="#FF6900">
	
	@include('partials.seo')

    @foreach (LaravelLocalization::getSupportedLocales() as $code => $locale)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ LaravelLocalization::getLocalizedURL($code, null, [], true) }}">
    @endforeach

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">


	@vite(['resources/css/app.css', 'resources/js/app.js'])
    @isset($pageCss)
        @vite("resources/css/pages/{$pageCss}.css")
    @endisset
    @stack('styles')
</head>
<body data-page="@yield('page', $page ?? 'home')">

<x-icons.sprite />

<div class="shell">
    <x-layout.topbar />
    <x-layout.header />
    <x-layout.nav :active="$__env->yieldContent('nav', $nav ?? 'home')" />
    {{ $slot ?? '' }}
    @yield('content')

    <x-layout.footer />
</div>

<x-layout.tabbar :active="$__env->yieldContent('tab', $tab ?? 'home')" />

{{-- overlays --}}
<div class="scrim" id="scrim" hidden></div>
<x-drawers.catalog />
<x-drawers.cart />
<div class="modal" id="authModal" hidden role="dialog" aria-modal="true" aria-labelledby="authHeroTitle">
    <div class="modal__scrim" data-close></div>
    <livewire:auth-modal />
</div>
<div class="toast" id="toast" role="status" hidden></div>
<x-layout.cookies />

@stack('overlays')

<script>
    window.IAPI = {
        locale: @json(app()->getLocale()),
        homeUrl: @json(route('home')),
        catalogUrl: @json(route('catalog')),
        flash: @json(session('toast')),
        i18n: @json($i18n),
    };
</script>
@stack('scripts')
@isset($pageJs)
    @vite("resources/js/pages/{$pageJs}.js")
@endisset
</body>
</html>
