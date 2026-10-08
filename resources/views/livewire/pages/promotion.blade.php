<div>
    <x-page-bar :back="route('promotions')" :title="$campaign['title']" heart />

    <main class="page">
        <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
            <a href="{{ route('home') }}">{{ __('common.home') }}</a>
            <x-icon name="caret-right" size="12" />
            <a href="{{ route('promotions') }}">{{ __('promo.all') }}</a>
            <x-icon name="caret-right" size="12" />
            <b>{{ $campaign['title'] }}</b>
        </nav>

        <div class="page__head">
            <h1 class="page__title">
                <span class="page__bar" aria-hidden="true"></span>
                {{ $campaign['title'] }}
            </h1>

            @if ($campaign['subtitle'])
                <span class="page__count">{{ $campaign['subtitle'] }}</span>
            @endif
        </div>

        @if ($campaign['ends_at'])
            {{-- a campaign with no end date simply says nothing, rather than "until null" --}}
            <p class="promo-page__until">
                {{ __('promo.until', ['date' => \Illuminate\Support\Carbon::parse($campaign['ends_at'])->translatedFormat('j F')]) }}
            </p>
        @endif

        <div class="cat-grid">
            @foreach ($campaign['products'] as $p)
                <x-product-card :product="$p" wire:key="promo-{{ $p['id'] }}" />
            @endforeach
        </div>

        @if (! count($campaign['products']))
            <div class="empty">
                <b>{{ __('promo.empty') }}</b>
                <span>{{ __('promo.empty_text') }}</span>
                <a class="load-more" href="{{ route('catalog') }}">{{ __('promo.to_catalog') }}</a>
            </div>
        @endif
    </main>
</div>
