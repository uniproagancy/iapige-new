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

    $seo = \App\Support\Seo::meta(array_merge(
        [
            'title'       => trim($__env->yieldContent('title')) ?: ($title ?? null),
            'description' => trim($__env->yieldContent('description')) ?: ($description ?? null),
        ],
        array_filter($seo ?? [], fn ($v) => $v !== null && $v !== ''),
    ));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="theme-color" content="#FF6900">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/icons/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/icons/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/icons/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    @include('partials.seo')
    @include('partials.schema')
    @include('partials.pixel')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

	@vite(['resources/css/app.css', 'resources/js/app.js'])
    @isset($pageCss)
        @vite("resources/css/pages/{$pageCss}.css")
    @endisset
    <style>.card__monthly{background:#e4f7ec;color:#16914e}</style>
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
<script type="text/javascript">
    var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
    function loadTawk() {
        var s1 = document.createElement("script"), s0 = document.getElementsByTagName("script")[0];
        s1.async = true;
        s1.src = 'https://embed.tawk.to/686bd1bca86aec190ca6b61d/1iviimin6';
        s1.charset = 'UTF-8';
        s1.setAttribute('crossorigin', '*');
        s0.parentNode.insertBefore(s1, s0);
    }
    window.addEventListener('scroll', loadTawk, { once: true });
    window.addEventListener('mousemove', loadTawk, { once: true });
    window.addEventListener('touchstart', loadTawk, { once: true });
</script>
</body>
</html>
