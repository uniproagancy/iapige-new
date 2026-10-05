@props(['brands'])

<section class="brands" aria-label="{{ __('home.brands') }}">
    <h2 class="m-title">{{ __('home.brands') }}</h2>
    <div class="brands__wrap">
        <div class="brands__rail" id="brandsRail">
            @foreach ($brands as $brand)
                <a class="brand" href="{{ $brand['url'] }}" aria-label="{{ $brand['name'] }}">
                    <img class="brand__logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" loading="lazy">
                    @if ($brand['count'])
                        <span class="brand__count">{{ __('common.products_count', ['count' => $brand['count']]) }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        <button type="button" class="brands__btn brands__btn--prev" data-rail="brandsRail" data-dir="-1" aria-label="{{ __('common.prev_items') }}"><x-icon name="caret-left" size="14" /></button>
        <button type="button" class="brands__btn brands__btn--next" data-rail="brandsRail" data-dir="1" aria-label="{{ __('common.next_items') }}"><x-icon name="caret-right" size="14" /></button>
    </div>
</section>
