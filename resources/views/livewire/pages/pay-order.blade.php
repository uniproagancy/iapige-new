<div class="page page--narrow">
    @if ($refused)

        <section class="card-soft">
            <h1 class="card-soft__title">{{ __('pay.refused.'.$refused.'_title') }}</h1>
            <p>{{ __('pay.refused.'.$refused) }}</p>

            <p class="placed__help">
                {{ __('order.questions') }}
                <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone', '0322121028')) }}">
                    {{ config('shop.phone', '032 212 10 28') }}
                </a>
            </p>
        </section>
    @else
        <section class="card-soft">
            <h1 class="card-soft__title">{{ __('pay.title', ['number' => $order->number]) }}</h1>
            <p>{{ __('pay.lead') }}</p>

            <div class="deliv">
                @foreach ($order->items as $item)
                    <div class="deliv__row" wire:key="i-{{ $item->id }}">
                        <span class="deliv__k">{{ $item->name }} × {{ $item->qty }}</span>
                        <span class="deliv__v">{{ money($item->price * $item->qty) }}</span>
                    </div>
                @endforeach

                <div class="deliv__row">
                    <span class="deliv__k">{{ __('checkout.shipping') }}</span>
                    <span class="deliv__v">
                        {{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}
                    </span>
                </div>

                <div class="deliv__row">
                    <span class="deliv__k"><b>{{ __('admin.total') }}</b></span>
                    <span class="deliv__v"><b>{{ money($order->total) }}</b></span>
                </div>

                <div class="deliv__row">
                    <span class="deliv__k">{{ __('checkout.payment') }}</span>
                    <span class="deliv__v">{{ $method?->name ?: $order->payment }}</span>
                </div>
            </div>

            <div class="placed__acts">
                <button type="button" class="btn-main" wire:click="pay" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="pay">{{ __('pay.go') }}</span>
                    <span wire:loading wire:target="pay">{{ __('pay.going') }}</span>
                </button>
            </div>
        </section>
    @endif

    @include('livewire.pages.checkout-bog-script')
</div>
