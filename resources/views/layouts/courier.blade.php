<!DOCTYPE html>
<html class="dark-layout" lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-layout="dark-layout">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ($title ?? __('delivery.title')).' — IAPI.GE' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/dashboard/admin.css', 'resources/dashboard/admin.js'])
    @livewireStyles
    @stack('styles')
    <style>
        body.courier-layout{margin:0;background:#161d31;min-height:100vh;color:#d0d2d6}
        .courier-nav{background:#283046;border-bottom:1px solid #3b4253;padding:16px 20px}
        .courier-nav-inner{max-width:1400px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
        .courier-brand{font-size:22px;font-weight:700;color:#ff7900!important;letter-spacing:.03em}
        .courier-nav-links{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .courier-main{max-width:1400px;margin:0 auto;padding:24px 18px 48px}
        @media(max-width:575px){.courier-nav{padding:14px 16px}.courier-nav-links{gap:12px}.courier-nav-name{display:none}.courier-main{padding:18px 12px 32px}}
    </style>
</head>
<body class="courier-layout">
<header class="courier-nav">
    <div class="courier-nav-inner">
        <a class="courier-brand" href="{{ route(($courierPreview ?? false) ? 'admin.deliveries.courier-preview' : 'courier.deliveries') }}">IAPI.GE</a>
        <nav class="courier-nav-links" aria-label="{{ __('delivery.courier_portal') }}">
            <span class="courier-nav-name text-muted">{{ auth()->user()->name }}</span>
            <a href="{{ route(($courierPreview ?? false) ? 'admin.deliveries.courier-preview' : 'courier.deliveries') }}">{{ __('delivery.title') }}</a>
            @if($courierPreview ?? false)
                <a href="{{ route('admin.deliveries') }}">{{ __('delivery.back_to_deliveries') }}</a>
            @endif
            <a href="{{ route('account') }}">{{ __('delivery.account') }}</a>
            <form action="{{ route('logout') }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('delivery.logout') }}</button>
            </form>
        </nav>
    </div>
</header>
<main class="courier-main">
    {{ $slot }}
</main>
@livewireScripts
@stack('scripts')
<script>
    window.IAPI_ADMIN = @json(['yes' => __('admin.yes'), 'cancel' => __('admin.cancel')]);
</script>
</body>
</html>
