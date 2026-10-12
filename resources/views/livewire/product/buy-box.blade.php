<div>
    {{--
        The colour and size options used to live here.

        They are navigation — each one opens a different product — so they are
        links now, in <x-product.variants>, beside the specifications they
        describe. This component keeps what genuinely needs a server: the
        quantity and the basket.
    --}}

    <div class="buyrow">
        {{-- a pre-order has nothing to count, so the stepper goes with the button --}}
        @if ($product->isSellable())
            <div class="stepper">
                <button type="button" wire:click="decrement" @disabled($qty <= 1) aria-label="{{ __('common.decrease') }}">
                    <x-icon name="minus" size="14" />
                </button>
                <span>{{ $qty }}</span>
                <button type="button" wire:click="increment" aria-label="{{ __('common.increase') }}">
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
            {{-- information, not an offer: it cannot be bought until it arrives --}}
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
