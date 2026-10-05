{{--
    Everything a crawler or a chat preview reads about this page.

    `$seo` is normalised by App\Support\Seo::meta() in the layout, so every key
    below is always present — a half-filled og: block is worse than none, as it
    makes a shared link look broken rather than plain.
--}}

<meta name="robots" content="{{ $seo['robots'] }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">

{{-- one entry per active language, plus x-default for anything unmatched --}}
@foreach (\App\Support\Seo::alternates() as $code => $href)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
@endforeach

<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:width" content="{{ \App\Support\Seo::IMAGE_WIDTH }}">
<meta property="og:image:height" content="{{ \App\Support\Seo::IMAGE_HEIGHT }}">
<meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

@foreach (\Mcamara\LaravelLocalization\Facades\LaravelLocalization::getSupportedLocales() as $code => $locale)
    @continue ($code === app()->getLocale())
    <meta property="og:locale:alternate" content="{{ str_replace('-', '_', $code) }}">
@endforeach

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

@if ($seo['price'])
    {{-- a price in the preview answers the only question a sharer's friends ask --}}
    <meta property="product:price:amount" content="{{ number_format((float) $seo['price'], 2, '.', '') }}">
    <meta property="product:price:currency" content="GEL">
    @if ($seo['availability'])
        <meta property="product:availability" content="{{ $seo['availability'] }}">
    @endif
@endif
