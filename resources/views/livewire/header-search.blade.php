<div class="search">
    <div class="search__box">
        <label class="sr-only" for="searchInput">{{ __('layout.search_label') }}</label>
        <input class="search__input" id="searchInput" type="search" autocomplete="off"
               placeholder="{{ __('layout.search_placeholder') }}"
               wire:model.live.debounce.300ms="q"
               role="combobox" aria-controls="searchResults">
        <button type="button" class="search__go" wire:click="clear" aria-label="{{ __('layout.search') }}">
            <x-icon name="magnifying-glass" size="16" />
        </button>
    </div>

    <div class="results" id="searchResults" role="listbox"
         @if (mb_strlen(trim($q)) < 2) hidden @endif>
        <div class="results__head">
            <span class="results__count">{{ __('catalog.found_count', ['count' => count($this->results)]) }}</span>
            <a class="results__all" href="{{ route('catalog') }}">{{ __('catalog.all_results') }}</a>
        </div>

        @forelse ($this->results as $hit)
            <a class="result" href="{{ $hit['url'] }}" role="option" wire:key="hit-{{ $hit['id'] }}">
                <img class="result__img" src="{{ $hit['thumb'] }}" alt="" loading="lazy">
                <span class="result__body">
                    <span class="result__name">{{ trim($hit['brand'].' '.$hit['name']) }}</span>
                    <span class="result__cat">{{ $hit['cat'] }}</span>
                </span>
                <span class="result__price">{{ money($hit['price']) }}</span>
            </a>
        @empty
            <div class="results__empty">{{ __('catalog.no_results') }}</div>
        @endforelse
    </div>
</div>