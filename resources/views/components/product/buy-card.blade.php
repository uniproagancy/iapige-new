@props([
    'product',            // the card array from Catalog::card()
    'services' => [],     // [[title, text], …]
    'model',              // the Eloquent product, for the Livewire components
])

<aside class="buycard" aria-label="{{ __('product.buy') }}">
    {{-- availability says what this is, not whether stock allows it --}}
    @if ($model->is_preorder)
        <div class="instock instock--pre">
            <span class="instock__tick" aria-hidden="true"><x-icon name="truck" size="12" /></span>
            <span>{{ $model->release_date
                ? __('product.expected_on', ['date' => $model->release_date->translatedFormat('j F')])
                : __('product.preorder_label') }}</span>
        </div>
    @elseif ($model->isListed())
        <div class="instock">
            <span class="instock__tick" aria-hidden="true"><x-icon name="check" size="12" /></span>
            <span>{{ __('product.in_stock') }}</span>
        </div>
    @else
        <div class="instock instock--out">
            <span class="instock__tick" aria-hidden="true"><x-icon name="x" size="12" /></span>
            <span>{{ __('product.not_available') }}</span>
        </div>
    @endif

    <div class="priceblock">
        <div class="priceblock__row">
            <span class="priceblock__now">{{ money($product['price']) }}</span>
            @if ($product['old'])
                <span class="priceblock__old">{{ money($product['old']) }}</span>
            @endif
        </div>

        <div class="priceblock__inst">
            <span>{{ __('product.installment') }}</span>
            <b>{{ __('product.per_month', ['amount' => $product['monthly']]) }}</b>
        </div>
    </div>

    {{-- options, quantity and add to cart --}}
    <livewire:product.buy-box
        :product="$model"
        :key="'buy-card-'.$model->id" />

    @if ($model->isSellable())
        <a class="btn-buy" href="{{ route('checkout', ['product' => $model->id]) }}">
            <x-icon name="credit-card" size="16" />
            <span class="btn-buy__label">{{ __('product.buy') }}</span>
            <span class="btn-buy__sep" aria-hidden="true"></span>
            <span class="btn-buy__note">{{ __('common.per_month', ['amount' => $product['monthly']]) }} · 0%</span>
        </a>
    @endif
    {{--
        The product goes with the request.

        The form takes a productId and this was the only place that renders it
        on a product page — and it was not passing one, so every call came in
        with no idea what it was about. The operator had the name and the
        number and nothing else, and the order written from it had no line.
    --}}
    <livewire:forms.callback-form :product-id="$model->id" :key="'callback-'.$model->id" />
</aside>
