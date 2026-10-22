@props(['section'])

@php $s = $section; @endphp

<section class="section" id="cat-{{ $s['slug'] }}">
    <div class="section__head">
        <div class="section__title">
            <span class="section__bar" aria-hidden="true"></span>
            <h2 class="section__name"><a href="{{ $s['url'] }}" style="color:inherit">{{ $s['name'] }}</a></h2>
            <span class="section__count">{{ $s['count'] }}</span>
        </div>
        <div class="section__subs">
            @foreach (array_slice($s['subs'], 0, 2) as $sub)
                <a class="pill" href="{{ $sub['url'] }}">{{ $sub['name'] }}</a>
            @endforeach
            <a class="pill pill--red" href="{{ $s['url'] }}">{{ __('common.all') }}</a>
        </div>
    </div>

    <div class="rail rail--section rail--fit" id="rail-{{ $s['slug'] }}">
        @foreach ($s['products'] as $product)
            <x-product-card :product="$product" />
        @endforeach
    </div>
</section>

@if ($s['banner'])
    @php $b = $s['banner']; @endphp
    <section class="banner" style="background:{{ $b['bg'] }}">
        <div class="banner__body">
            <span class="banner__ghost" aria-hidden="true">%</span>
            <span class="banner__kicker">{{ $b['kicker'] }}</span>
            <h2 class="banner__title">{{ $b['title'] }}</h2>
            <p class="banner__text">{{ $b['text'] }}</p>
            <a class="banner__cta" href="{{ $s['url'] }}">{{ $b['cta'] }}</a>
        </div>
        <div class="banner__media"><img src="{{ \App\Support\Store::img($b['img'], 1, 900, 420) }}" alt="" loading="lazy"></div>
    </section>
@endif

@if ($s['duo'])
    <div class="duo">
        @foreach ($s['duo'] as $i => $d)
            <section @class(['duo__item', 'duo__item--dark' => $i === 1])>
                <div class="duo__body">
                    <span class="duo__kicker">{{ $d['kicker'] }}</span>
                    <h2 class="duo__title">{{ $d['title'] }}</h2>
                    <p class="duo__text">{{ $d['text'] }}</p>
                    <a class="duo__cta" href="{{ $s['url'] }}">{{ $d['cta'] }}</a>
                </div>
                <div class="duo__media"><img src="{{ \App\Support\Store::img($d['img'], 1, 500, 400) }}" alt="" loading="lazy"></div>
            </section>
        @endforeach
    </div>
@endif
