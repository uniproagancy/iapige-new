@props(['groups', 'facets', 'picked', 'priceMin', 'priceMax', 'priceNow', 'found', 'current' => null, 'tree' => \App\Support\Catalog::tree()])

<aside class="filters" id="filters" aria-label="{{ __('catalog.filters') }}">
    <span class="filters__grip" aria-hidden="true"></span>

    <nav class="sidenav" aria-label="{{ __('catalog.categories') }}">
        <a class="sidenav__back" href="{{ route('home') }}">
            <x-icon name="caret-left" size="14" />
            {{ __('common.home') }}
        </a>
        <div class="catlist">
            @foreach ($tree as $cat)
                <a wire:navigate href="{{ $cat['url'] }}" @class(['catnav', 'is-on' => $cat['name'] === $current])>
                    <span class="catnav__name">{{ $cat['name'] }}</span>
                    <x-icon name="caret-right" size="14" class="catnav__chev" />
                </a>
            @endforeach
        </div>
        <button type="button" class="catlist__all" data-open="catalog">{{ __('catalog.see_all', ['count' => count($tree)]) }}</button>
    </nav>

    <div class="filters__head">
        <h2 class="filters__title">{{ __('catalog.filters') }}</h2>
        <button type="button" class="filters__clear" wire:click="clearFilters">{{ __('catalog.clear') }}</button>
    </div>

    <div class="fgroup is-open">
        <button type="button" class="fgroup__head" data-fgroup aria-expanded="true">
            <span class="fgroup__name">{{ __('catalog.price') }}</span>
            <x-icon name="caret-down" size="14" class="fgroup__chev" />
        </button>
        <div class="fgroup__body">
            <div class="price-row">
                <div class="price-box"><span>{{ __('catalog.from') }}</span><b>{{ money($priceMin) }}</b></div>
                <div class="price-box"><span>{{ __('catalog.to') }}</span><b>{{ money($priceNow) }}</b></div>
            </div>
            <label class="sr-only" for="priceRange">{{ __('catalog.price_max') }}</label>
            <input class="price-range" id="priceRange" type="range"
                   wire:model.live.debounce.400ms="price"
                   min="{{ $priceMin }}" max="{{ $priceMax }}" step="100" value="{{ $priceNow }}">
        </div>
    </div>

    @foreach ($groups as $group)
        @if (count($group['items']))
            <x-catalog.filter-group :group="$group" :facets="$facets" :picked="$picked" />
        @endif
    @endforeach

    <button type="button" class="filters__apply" data-close>{!! __('catalog.show', ['count' => '<span>'.$found.'</span>']) !!}</button>
</aside>
