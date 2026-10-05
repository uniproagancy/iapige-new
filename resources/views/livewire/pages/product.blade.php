<div>
    <x-page-bar :back="$product['breadcrumbs'] ? end($product['breadcrumbs'])['url'] : route('catalog')"
                :path="$product['cat'].' / '.$product['brand']" :wish-id="$model->id" />
    <nav class="crumbbar" aria-label="{{ __('catalog.breadcrumbs') }}">
        <a href="{{ route('home') }}">{{ __('common.home') }}</a><i>/</i>
        @foreach ($product['breadcrumbs'] as $crumb)
            <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a><i>/</i>
        @endforeach
        <b>{{ $product['brand'] }} {{ $product['name'] }}</b>
        <span class="crumbbar__code">SKU: {{ $product['id'] }}</span>
    </nav>
    <main class="pdp">
        <div class="pdp__left">
            <div class="pdp__top">
                <x-product.gallery :product="$product" />
                <x-product.info :product="$product" :rating="$rating" :key-specs="$keySpecs" />
            </div>
            <livewire:product.bundle :ids="$bundleIds" :key="'bundle-'.$model->id" />
        </div>
        <x-product.buy-card :product="$product" :services="$services"
                            :model="$model" :colors="$colors" :configs="$configs" />
    </main>
    <x-product.tabs :specs="$specs" :description="$product['description']"
                    :highlights="$highlights" :rating="$rating" :reviews="$reviews" />
    @if (count($related))
        <section class="related" aria-labelledby="relTitle">
            <div class="related__head">
                <div class="related__title">
                    <span class="related__bar" aria-hidden="true"></span>
                    <h2 class="related__name" id="relTitle">{{ __('product.related') }}</h2>
                </div>
                <a class="pill pill--red" href="{{ route('catalog') }}">{{ __('common.all') }}</a>
            </div>
            <div class="related__grid">
                @foreach ($related as $p)
                    <x-product-card :product="$p" wire:key="rel-{{ $p['id'] }}" />
                @endforeach
            </div>
        </section>
    @endif
    <div class="actionbar">
        <livewire:product.buy-box :product="$model" :colors="$colors" :configs="$configs" :key="'buy-bar-'.$model->id" />
        <a class="btn-buy" href="{{ route('checkout', ['product' => $model->id]) }}">
            <span class="btn-buy__label">{{ __('product.buy') }}</span>
            <span class="btn-buy__sep" aria-hidden="true"></span>
            <span class="btn-buy__note">{{ __('common.per_month', ['amount' => $product['monthly']]) }} · 0%</span>
        </a>
    </div>
</div>