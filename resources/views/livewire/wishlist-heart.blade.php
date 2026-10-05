<button type="button"
        @class([
            'btn-wish' => $variant === 'card',
            'btn-side' => $variant === 'side',
            'pagebar__btn pagebar__btn--wish' => $variant === 'bar',
            'is-on' => $on,
        ])
        wire:click="toggle"
        aria-pressed="{{ $on ? 'true' : 'false' }}"
        aria-label="{{ __('card.wish') }}">
    <x-icon :name="$on ? 'heart-fill' : 'heart'" size="18" />
</button>
