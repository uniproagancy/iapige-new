@props(['deals', 'deadline'])

<section class="promo" id="promo" aria-labelledby="promoTitle">
    <div class="promo__head">
        <div class="promo__left">
            <span class="promo__badge">{{ __('home.deals_badge') }}</span>
            <span class="promo__badge--m">SALE</span>
            <div>
                <h2 class="promo__title" id="promoTitle">{{ __('home.deals_title') }}</h2>
                <span class="promo__title--m">{{ __('home.deals_short') }}</span>
                <span class="promo__note">{{ __('home.deals_note') }}</span>
            </div>
        </div>
    </div>

    <div class="rail-wrap">
        <div class="rail rail--fit" id="dealsRail">
            @foreach ($deals as $deal)
                <x-product-card :product="$deal" variant="deal" />
            @endforeach
        </div>
    </div>
</section>
