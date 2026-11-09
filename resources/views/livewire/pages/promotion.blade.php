<div>
    <x-page-bar :back="route('promotions')" :title="$campaign['title']"
                :count="new \Illuminate\Support\HtmlString($total.' / '.$onOffer.' '.__('common.products'))" heart>
        @if (count($departments) > 1)
            <div class="chips-row">
                <button type="button" @class(['chip-sub', 'is-on' => ! $categoryId])
                        wire:click="chooseCategory(null)">{{ __('promo.every_department') }}</button>
                @foreach ($departments as $department)
                    <button type="button" @class(['chip-sub', 'is-on' => $categoryId === $department['id']])
                            wire:click="chooseCategory({{ $department['id'] }})"
                            wire:key="chip-d-{{ $department['id'] }}">{{ $department['name'] }}</button>
                @endforeach
            </div>
        @endif
    </x-page-bar>

    <main class="page">
        <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
            <a href="{{ route('home') }}">{{ __('common.home') }}</a>
            <x-icon name="caret-right" size="12" />
            <a href="{{ route('promotions') }}">{{ __('promo.all') }}</a>
            <x-icon name="caret-right" size="12" />
            @if ($categoryId)
                <a href="{{ route('promotion', $campaign['slug']) }}" wire:click.prevent="chooseCategory(null)">{{ $campaign['title'] }}</a>
                <x-icon name="caret-right" size="12" />
                <b>{{ $listing['title'] }}</b>
            @else
                <b>{{ $campaign['title'] }}</b>
            @endif
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
            <p class="promo-page__until">
                {{ __('promo.until', ['date' => \Illuminate\Support\Carbon::parse($campaign['ends_at'])->translatedFormat('j F')]) }}
            </p>
        @endif

        @if (! $onOffer)
            <div class="empty">
                <b>{{ __('promo.empty') }}</b>
                <span>{{ __('promo.empty_text') }}</span>
                <a class="load-more" href="{{ route('catalog') }}">{{ __('promo.to_catalog') }}</a>
            </div>
        @else
            @include('livewire.pages.partials.listing', [
                'sidenav' => 'livewire.pages.partials.promotion-sidenav',
                'subsInPlace' => true,
            ])
        @endif
    </main>
</div>
