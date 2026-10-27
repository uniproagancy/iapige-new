<div x-data>

    <div class="buyrow">

        @if ($product->isSellable())
            <div class="stepper">
                <button type="button" wire:click="decrement" @disabled($qty <= 1)
                        x-on:click="$dispatch('quick-order-quantity-pending', { productId: {{ $productId }} })"
                        wire:loading.attr="disabled" wire:target="increment,decrement"
                        aria-label="{{ __('common.decrease') }}">
                    <x-icon name="minus" size="14" />
                </button>
                <span>{{ $qty }}</span>
                <button type="button" wire:click="increment" @disabled($qty >= 99)
                        x-on:click="$dispatch('quick-order-quantity-pending', { productId: {{ $productId }} })"
                        wire:loading.attr="disabled" wire:target="increment,decrement"
                        aria-label="{{ __('common.increase') }}">
                    <x-icon name="plus" size="14" />
                </button>
            </div>

            <button type="button" @class(['btn-main', 'is-added' => $added])
                    wire:click="add"
                    wire:loading.attr="disabled"
                    wire:target="add">
                <x-icon :name="$added ? 'check' : 'shopping-bag'" size="18" />
                <span wire:loading.remove wire:target="add">
                    {{ $added ? __('product.added') : __('cart.add') }}
                </span>
                <span wire:loading wire:target="add">{{ __('common.wait') }}</span>
            </button>
        @elseif ($product->is_preorder)

            <button type="button" class="btn-main is-pre" disabled>
                <x-icon name="truck" size="18" />
                {{ __('product.preorder_label') }}
            </button>
        @else
            <button type="button" class="btn-main is-off" disabled>
                <x-icon name="x" size="18" />
                {{ __('product.not_available') }}
            </button>
        @endif

        <livewire:wishlist-heart :product-id="$productId" variant="side" :key="'wish-buy-'.$productId" />
    </div>

    @if ($product->is_preorder)
        <p class="buyrow__pre">
            <x-icon name="truck" size="14" />
            {{ $product->release_date
                ? __('product.expected_on', ['date' => $product->release_date->translatedFormat('j F')])
                : __('product.expected_soon') }}
        </p>
    @endif
</div>
