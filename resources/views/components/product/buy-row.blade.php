{{-- quantity + add to cart + wishlist; lives inside the Product page component --}}
@props(['product', 'qty'])

<div {{ $attributes->class(['buyrow']) }}>
    <div class="stepper">
        <button type="button" wire:click="decrement" aria-label="{{ __('product.quantity_down') }}"><x-icon name="minus" size="14" /></button>
        <span>{{ $qty }}</span>
        <button type="button" wire:click="increment" aria-label="{{ __('product.quantity_up') }}"><x-icon name="plus" size="14" /></button>
    </div>
    <button type="button" class="btn-main" wire:click="addToCart" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="addToCart"><x-icon name="shopping-bag" size="18" />{{ __('product.add') }}</span>
        <span wire:loading wire:target="addToCart">{{ __('product.adding') }}</span>
    </button>
    <button type="button" class="btn-side" data-wish="{{ $product['id'] }}" aria-label="{{ __('card.wish') }}" aria-pressed="false">
        <x-icon name="heart" size="18" />
    </button>
</div>
