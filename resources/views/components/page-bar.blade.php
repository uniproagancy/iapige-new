@props(['back', 'title' => null, 'count' => null, 'path' => null, 'wishId' => null, 'heart' => false])

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

        @if ($wishId)
            <livewire:wishlist-heart :product-id="$wishId" variant="bar" :key="'wish-bar-'.$wishId" />
        @elseif ($heart)
            <a class="pagebar__btn pagebar__btn--wish" href="#" aria-label="{{ __('layout.wishlist') }}"><x-icon name="heart" size="18" /></a>
        @endif
    </div>
    {{ $slot }}
</div>
