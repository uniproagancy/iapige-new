
<meta name="robots" content="{{ $seo['robots'] }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">

@foreach (\App\Support\Seo::alternates() as $code => $href)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
@endforeach

<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['og_url'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:width" content="{{ $seo['image_width'] }}">
<meta property="og:image:height" content="{{ $seo['image_height'] }}">
<meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
@php
    $ogLocales = [];
    $ogLocaleFallbacks = ['ka' => 'ka_GE', 'en' => 'en_GB'];
    foreach (\Mcamara\LaravelLocalization\Facades\LaravelLocalization::getSupportedLocales() as $code => $locale) {
        $regional = is_array($locale) ? ($locale['regional'] ?? null) : null;
        $regional = is_string($regional) ? str_replace('-', '_', $regional) : '';
        if (! preg_match('/\A[a-z]{2}_[A-Z]{2}\z/D', $regional)) {
            $regional = $ogLocaleFallbacks[$code] ?? '';
        }
        if ($regional !== '') {
            $ogLocales[$code] = $regional;
        }
    }
    $ogCurrentLocale = $ogLocales[app()->getLocale()] ?? null;
    $ogAvailability = match ($seo['availability']) {
        'https://schema.org/InStock', 'http://schema.org/InStock' => 'in stock',
        'https://schema.org/OutOfStock', 'http://schema.org/OutOfStock' => 'out of stock',
        'https://schema.org/PreOrder', 'http://schema.org/PreOrder' => 'preorder',
        'https://schema.org/BackOrder', 'http://schema.org/BackOrder' => 'available for order',
        'https://schema.org/Discontinued', 'http://schema.org/Discontinued' => 'discontinued',
        default => null,
    };
@endphp
@if ($ogCurrentLocale !== null)
    <meta property="og:locale" content="{{ $ogCurrentLocale }}">
@endif

@foreach ($ogLocales as $regional)
    @continue ($regional === $ogCurrentLocale)
    <meta property="og:locale:alternate" content="{{ $regional }}">
@endforeach

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

@if ($seo['price'])

    <meta property="product:price:amount" content="{{ number_format((float) $seo['price'], 2, '.', '') }}">
    <meta property="product:price:currency" content="GEL">
    @if ($ogAvailability !== null)
        <meta property="product:availability" content="{{ $ogAvailability }}">
    @endif
@endif
