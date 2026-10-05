@props(['product'])

<section class="gal" aria-label="{{ __('product.photos') }}" data-gallery='@json($product['gallery'])'>
    <div class="gal__main">
        <img class="gal__img" id="galImg" src="{{ $product['gallery'][0] }}" alt="{{ $product['brand'] }} {{ $product['name'] }}">
        <span class="gal__ph">{{ __('product.photo_placeholder') }}</span>
        @if ($product['discount'])
            <span class="gal__disc">{{ $product['discount'] }}</span>
        @endif
        @if (count($product['gallery']) > 1)
            <button type="button" class="gal__nav gal__nav--prev" data-gal-step="-1" aria-label="{{ __('common.prev_photo') }}"><x-icon name="caret-left" size="14" /></button>
            <button type="button" class="gal__nav gal__nav--next" data-gal-step="1" aria-label="{{ __('common.next_photo') }}"><x-icon name="caret-right" size="14" /></button>
            <div class="gal__dots">
                @foreach ($product['gallery'] as $i => $src)
                    <button type="button" @class(['gal__dot', 'is-on' => $i === 0]) data-gal-go="{{ $i }}" aria-label="{{ __('common.photo', ['n' => $i + 1]) }}"></button>
                @endforeach
            </div>
        @endif
    </div>
    <div class="gal__thumbs">
        @foreach ($product['thumbs'] as $i => $src)
            <button type="button" @class(['thumb', 'is-on' => $i === 0]) data-gal-go="{{ $i }}" aria-label="{{ __('common.photo', ['n' => $i + 1]) }}">
                <img src="{{ $src }}" alt="" loading="lazy">
                <span class="thumb__n">0{{ $i + 1 }}</span>
            </button>
        @endforeach
    </div>
</section>
