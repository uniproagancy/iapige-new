<div class="cart__panel">
    <div class="drawer__head">
        <h2 class="drawer__title">{{ __('cart.title') }} <span class="cart__count">{{ $summary['count'] }}</span></h2>
        <div class="drawer__actions">
            @if ($summary['count'])
                <button type="button" class="cart__clear"
                        wire:click="clear"
                        wire:confirm="{{ __('cart.clear_confirm') }}">{{ __('cart.clear') }}</button>
            @endif
            <button type="button" class="drawer__close" data-close aria-label="{{ __('common.close') }}"><x-icon name="x" size="16" /></button>
        </div>
    </div>
    <div class="cart__items">
        @forelse ($lines as $line)
            <div class="cart-item" wire:key="line-{{ $line['id'] }}">
                <img class="cart-item__img" src="{{ $line['img'] }}" alt="" loading="lazy">

                <div class="cart-item__body">
                    <span class="cart-item__name">{{ $line['name'] }}</span>
                    <span class="cart-item__cat">{{ $line['cat'] }}</span>
                    <div class="cart-item__row">
                        <div class="qty">
                            <button type="button" wire:click="decrement({{ $line['id'] }})" aria-label="{{ __('cart.decrease') }}"><x-icon name="minus" size="14" /></button>
                            <span>{{ $line['qty'] }}</span>
                            <button type="button" wire:click="increment({{ $line['id'] }})" aria-label="{{ __('cart.increase') }}"><x-icon name="plus" size="14" /></button>
                        </div>
                        <span class="cart-item__price">{{ money($line['sum']) }}</span>
                    </div>
                </div>

                <button type="button" class="cart-item__remove"
                        wire:click="remove({{ $line['id'] }})"
                        aria-label="{{ __('cart.remove') }}">
                    <x-icon name="x" size="12" />
                </button>
            </div>
        @empty
            <div class="cart__empty">
                <b>{{ __('cart.empty') }}</b>
                <span>{{ __('cart.empty_text') }}</span>
                <a class="pill pill--red" href="{{ route('catalog') }}">{{ __('cart.to_catalog') }}</a>
            </div>
        @endforelse
    </div>

    <div class="cart__foot">
        <div class="cart__line"><span>{{ __('cart.items', ['count' => $summary['count']]) }}</span><b>{{ $summary['total'] }}</b></div>
        <div class="cart__total"><span>{{ __('cart.total') }}</span><b>{{ $summary['total'] }}</b></div>
        <a href="{{ route('checkout') }}" class="btn-checkout" data-checkout @disabled(! $summary['count'])>{{ __('cart.checkout') }}</a>
    </div>
</div>