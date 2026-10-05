@php
    $isOnline = $order->is_paid;
    $steps = [
        ['t' => __('order.next_call'),    's' => __('order.next_call_text')],
        ['t' => __('order.next_pack'),    's' => __('order.next_pack_text')],
        ['t' => __('order.next_deliver'), 's' => __('order.next_deliver_text', ['days' => config('shop.delivery_days', 2)])],
    ];
@endphp

<div class="page page--narrow">
    {{-- ------------------------------------------------------------ the answer --}}
    <section class="placed">
        <span class="placed__tick" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </span>

        <h1 class="placed__title">{{ __('order.thank_you') }}</h1>
        <p class="placed__text">{{ __('order.placed_text') }}</p>

        <div class="placed__num">
            <span class="placed__numLabel">{{ __('order.your_number') }}</span>
            <span class="placed__numValue">#{{ $order->number }}</span>
        </div>

        @if ($order->email)
            <p class="placed__mail">{{ __('order.sent_to', ['email' => $order->email]) }}</p>
        @endif
    </section>

    {{-- ------------------------------------------------------------ what happens next --}}
    <section class="card-soft">
        <h2 class="card-soft__title">{{ __('order.what_next') }}</h2>

        <ol class="nextlist">
            @foreach ($steps as $i => $step)
                <li class="nextlist__item">
                    <span class="nextlist__n">{{ $i + 1 }}</span>
                    <span class="nextlist__body">
                        <span class="nextlist__t">{{ $step['t'] }}</span>
                        <span class="nextlist__s">{{ $step['s'] }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ------------------------------------------------------------ what was bought --}}
    <section class="card-soft">
        <h2 class="card-soft__title">{{ __('order.summary') }}</h2>

        <div class="sum">
            @foreach ($order->items as $item)
                <div class="sum__line">
                    <span class="sum__name">
                        {{ $item->name }}
                        @if ($item->is_preorder)
                            <span class="sum__pre">{{ __('product.preorder_label') }}</span>
                        @endif
                    </span>
                    <span class="sum__qty">x {{ $item->qty }}</span>
                    <span class="sum__price">{{ money($item->price * $item->qty) }}</span>
                </div>
            @endforeach

            <div class="sum__row">
                <span>{{ __('checkout.subtotal') }}</span>
                <span>{{ money($order->subtotal) }}</span>
            </div>

            @if ($order->discount)
                <div class="sum__row sum__row--off">
                    <span>{{ __('checkout.discount') }}</span>
                    <span>−{{ money($order->discount) }}</span>
                </div>
            @endif

            <div class="sum__row">
                <span>{{ __('checkout.shipping') }}</span>
                <span>{{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}</span>
            </div>

            <div class="sum__row sum__row--total">
                <span>{{ __('checkout.total') }}</span>
                <span>{{ money($order->total) }}</span>
            </div>
        </div>
    </section>

    {{-- ------------------------------------------------------------ where it goes --}}
    <section class="card-soft">
        <h2 class="card-soft__title">{{ __('order.delivery_to') }}</h2>

        <div class="deliv">
            <div class="deliv__row">
                <span class="deliv__k">{{ __('checkout.name') }}</span>
                <span class="deliv__v">{{ $order->name }}</span>
            </div>
            <div class="deliv__row">
                <span class="deliv__k">{{ __('checkout.phone') }}</span>
                <span class="deliv__v">{{ $order->phone }}</span>
            </div>
            <div class="deliv__row">
                <span class="deliv__k">{{ __('checkout.address') }}</span>
                <span class="deliv__v">{{ collect([$order->city, $order->address])->filter()->implode(', ') }}</span>
            </div>
            <div class="deliv__row">
                <span class="deliv__k">{{ __('checkout.payment') }}</span>
                <span class="deliv__v">
                    {{ $isOnline ? __('order.paid_online') : __('order.pay_on_delivery') }}
                </span>
            </div>

            @if ($order->comment)
                <div class="deliv__row">
                    <span class="deliv__k">{{ __('checkout.comment') }}</span>
                    <span class="deliv__v">{{ $order->comment }}</span>
                </div>
            @endif
        </div>
    </section>

    {{-- ------------------------------------------------------------ where to go now --}}
    <div class="placed__acts">
        @auth
            <a class="btn-main" href="{{ route('account', ['tab' => 'orders']) }}">{{ __('order.track_it') }}</a>
        @endauth

        <a class="btn-ghost" href="{{ route('catalog') }}">{{ __('order.keep_shopping') }}</a>
    </div>

    {{-- a phone number, because this is exactly when people want one --}}
    <p class="placed__help">
        {{ __('order.questions') }}
        <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone', '0322121028')) }}">
            {{ config('shop.phone', '032 212 10 28') }}
        </a>
    </p>
</div>
