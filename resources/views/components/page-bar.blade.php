@props(['back', 'title' => null, 'count' => null, 'path' => null, 'wishId' => null, 'heart' => false, 'search' => true])

<div class="pagebar">
    <div class="pagebar__row">
        <a class="pagebar__btn" wire:navigate href="{{ $back }}" aria-label="{{ __('common.back') }}"><x-icon name="caret-left" size="16" /></a>

        @if ($path)
            <span class="pagebar__path">{{ $path }}</span>
        @elseif ($count !== null)
            <div class="pagebar__body">
                <span class="pagebar__title">{{ $title }}</span>
                <span class="pagebar__count">{{ $count }}</span>
            </div>
        @else
            <span class="pagebar__title">{{ $title }}</span>
        @endif

        {{-- These pages put this bar where the header is on a phone, and the
             header is what holds the search. The sheet brings it back without
             going anywhere; on a wide screen the header is there and this bar
             is not drawn at all. --}}
        @if ($search)
            <button type="button" class="pagebar__btn" data-search-tab aria-label="{{ __('layout.search') }}">
                <x-icon name="magnifying-glass" size="18" />
            </button>
        @endif

        @if ($wishId)
            <livewire:wishlist-heart :product-id="$wishId" variant="bar" :key="'wish-bar-'.$wishId" />
        @elseif ($heart)
            <a class="pagebar__btn pagebar__btn--wish" href="#" aria-label="{{ __('layout.wishlist') }}"><x-icon name="heart" size="18" /></a>
        @endif
    </div>
    {{ $slot }}
</div>
