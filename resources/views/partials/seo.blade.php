{{--
    Social preview tags.

    A link shared without them shows the page's raw URL, which reads like spam
    — and most of a small shop's traffic arrives through somebody sharing one.
--}}
@php
    $seoTitle = $seo['title'] ?? __('layout.title');
    $seoDescription = \Illuminate\Support\Str::limit(strip_tags($seo['description'] ?? __('layout.description')), 200);
    $seoImage = $seo['image'] ?? asset('img/og-default.jpg');
@endphp

<meta property="og:site_name" content="ELIO">
<meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:image" content="{{ $seoImage }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImage }}">

@if (! empty($seo['price']))
    {{-- a price in the preview answers the only question a sharer's friends ask --}}
    <meta property="product:price:amount" content="{{ $seo['price'] }}">
    <meta property="product:price:currency" content="GEL">
@endif

<link rel="canonical" href="{{ $seo['canonical'] ?? url()->current() }}">
