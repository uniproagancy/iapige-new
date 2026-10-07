@props(['product', 'rating', 'keySpecs'])

<section class="info" aria-label="{{ __('product.info') }}">
    <div class="info__head">
        <div class="info__brandrow">
            <span class="info__brand">{{ $product['brand'] }}</span>
        </div>
        <h1 class="info__title" style="font-size: 18px">{{ $product['name'] }}</h1>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px">
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
