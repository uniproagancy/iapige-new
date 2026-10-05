@props(['product', 'rating', 'keySpecs'])

<section class="info" aria-label="{{ __('product.info') }}">
    <div class="info__head">
        <div class="info__brandrow">
            <span class="info__brand">{{ $product['brand'] }}</span>
        </div>
        <h1 class="info__title">{{ $product['name'] }}</h1>
        <div class="rating">
            @if ($rating['count'])
                <div class="rating__group">
                    <div class="stars" aria-label="{{ __('product.rating', ['score' => $rating['score']]) }}">
                        @for ($i = 1; $i <= 5; $i++)
                            <span @class(['is-on' => $i <= floor($rating['score'])])></span>
                        @endfor
                    </div>
                    <span class="rating__num">{{ $rating['score'] }}</span>
                    <span class="rating__count">· {{ __('product.reviews_count', ['count' => $rating['count']]) }}</span>
                </div>
                <span class="rating__sep" aria-hidden="true"></span>
            @endif
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px">
        <div class="block-head">
            <span class="block-head__i" aria-hidden="true">i</span>
            <span class="block-head__t">{{ __('product.key_specs') }}</span>
        </div>
        <div class="keyspecs">
            @foreach ($keySpecs as [$k, $v])
                <div class="krow"><span class="krow__k">{{ $k }}</span><span class="krow__v">{{ $v }}</span></div>
            @endforeach
        </div>
        <button type="button" class="showall" data-tab="specs" data-scroll-tabs>
            <x-icon name="caret-right" size="12" />
            {{ __('product.show_all') }}
        </button>
    </div>
</section>
