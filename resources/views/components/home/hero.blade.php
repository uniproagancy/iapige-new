@props(['slides'])

<section class="hero" id="hero" aria-label="{{ __('home.slides') }}" aria-roledescription="carousel" data-hero>
    <div id="heroSlides">
        @foreach ($slides as $i => $s)
            <div @class(['hero__slide', 'is-active' => $i === 0]) data-slide="{{ $i }}"
                 role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ count($slides) }}"
                 @if($i) aria-hidden="true" @endif>
                <img class="hero__img" src="https://picsum.photos/id/{{ $s['img'] }}/1440/530" alt="" loading="{{ $i ? 'lazy' : 'eager' }}">
                <span class="hero__veil"></span>
                <div class="hero__copy">
                    <span class="hero__dates">{{ $s['dates'] }}</span>
                    <h2 class="hero__title">{{ $s['title'] }}</h2>
                    <span class="hero__sub">{{ $s['sub'] }}</span>
                    <div class="hero__cta">
                        <a class="hero__btn" href="{{ route('catalog') }}">{{ $s['cta'] }}</a>
                        <button type="button" class="hero__btn hero__btn--ghost" data-open="catalog">{{ __('home.see_catalog') }}</button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="hero__dots" role="tablist" aria-label="{{ __('home.slides') }}">
        @foreach ($slides as $i => $s)
            <button type="button" @class(['hero__dot', 'is-active' => $i === 0]) data-dot="{{ $i }}"
                    role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" aria-label="{{ __('home.slide', ['n' => $i + 1]) }}"></button>
        @endforeach
    </div>
    <button type="button" class="nav-round nav-round--prev" data-hero-step="-1" aria-label="{{ __('home.prev_slide') }}"><x-icon name="caret-left" size="14" /></button>
    <button type="button" class="nav-round nav-round--next" data-hero-step="1" aria-label="{{ __('home.next_slide') }}"><x-icon name="caret-right" size="14" /></button>
</section>
