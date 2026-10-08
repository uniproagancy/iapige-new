@props(['deals', 'deadline', 'campaign' => null])

{{--
    Nothing to offer, nothing to announce.

    The banner used to render whatever happened to be in the rail, so a shop
    with no campaign running still promised "this week's offer" above an empty
    strip — and until there was a way to put products into a campaign, that
    was every shop. An empty promise is worse than no banner.
--}}
@if (count($deals))
    <section class="promo" id="promo" aria-labelledby="promoTitle">
        <div class="promo__head">
            <div class="promo__left">
                <span class="promo__badge">{{ __('home.deals_badge') }}</span>
                <span class="promo__badge--m">SALE</span>
                <div>
                    {{-- the campaign names itself when it has a name --}}
                    <h2 class="promo__title" id="promoTitle">
                        {{ $campaign?->title ?: __('home.deals_title') }}
                    </h2>
                    <span class="promo__title--m">{{ __('home.deals_short') }}</span>
                    <span class="promo__note">{{ $campaign?->subtitle ?: __('home.deals_note') }}</span>
                </div>
            </div>

            {{-- the rail shows a handful; the campaign's own page shows all of it --}}
            @if ($campaign)
                <a class="promo__all" href="{{ route('promotion', $campaign->code) }}">
                    {{ __('common.all') }}
                    <x-icon name="caret-right" size="12" />
                </a>
            @endif
        </div>

        <div class="rail-wrap">
            <div class="rail rail--fit" id="dealsRail">
                @foreach ($deals as $deal)
                    <x-product-card :product="$deal" variant="deal" />
                @endforeach
            </div>
        </div>
    </section>
@endif
