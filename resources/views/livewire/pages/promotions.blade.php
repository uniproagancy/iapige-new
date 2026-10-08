<div>
    <x-page-bar :back="route('home')" :title="__('promo.all')" heart />

    <main class="page">
        <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
            <a href="{{ route('home') }}">{{ __('common.home') }}</a>
            <x-icon name="caret-right" size="12" />
            <b>{{ __('promo.all') }}</b>
        </nav>

        <div class="page__head">
            <h1 class="page__title">
                <span class="page__bar" aria-hidden="true"></span>
                {{ __('promo.all') }}
            </h1>
            <span class="page__count">{{ __('promo.all_lead') }}</span>
        </div>

        @if (count($campaigns))
            <div class="promo-list">
                @foreach ($campaigns as $campaign)
                    <a class="promo-card" href="{{ $campaign['url'] }}">
                        <span class="promo-card__badge">{{ $campaign['badge'] ?: __('home.deals_badge') }}</span>

                        <span class="promo-card__body">
                            <span class="promo-card__title">{{ $campaign['title'] }}</span>
                            @if ($campaign['subtitle'])
                                <span class="promo-card__note">{{ $campaign['subtitle'] }}</span>
                            @endif
                        </span>

                        <span class="promo-card__count">
                            {{ __('common.products_count', ['count' => $campaign['count']]) }}
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="empty">
                <b>{{ __('promo.none') }}</b>
                <span>{{ __('promo.none_text') }}</span>
                <a class="load-more" href="{{ route('catalog') }}">{{ __('promo.to_catalog') }}</a>
            </div>
        @endif
    </main>
</div>
