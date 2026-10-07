@props(['p' => null, 'product' => null])

@php
    $p = $p ?? $product;

    $payload = \App\Support\Catalog::cartPayload($p);
    $json = json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
@endphp

<article class="card"
         data-card
         data-product-id="{{ $p['id'] }}"
         data-product-name="{{ trim(($p['brand'] ?? '').' '.$p['name']) }}"
         data-index="0"
         data-order="{{ $p['order'] ?? 0 }}">
    <a class="card__media" href="{{ $p['url'] }}">
        <img class="card__img" src="{{ $p['thumb'] }}" alt="{{ $p['name'] }}" loading="lazy">

        @if (! empty($p['preorder']))
            {{-- a pre-order outranks "sale" and "new": it changes how you buy it --}}
            <span class="card__tag card__tag--pre">{{ __('card.preorder') }}</span>
        @elseif (! empty($p['tag']))
            <span @class(['card__tag', 'card__tag--sale' => $p['tag'] === 'sale', 'card__tag--new' => $p['tag'] === 'new'])>
                {{ __('card.tag_'.$p['tag']) }}
            </span>
        @endif

        @if (! empty($p['discount']))
            <span class="card__discount">{{ $p['discount'] }}</span>
        @endif
    </a>

    <div class="card__body">
        @if ($p['brand'])
            <span class="card__brand">{{ $p['brand'] }}</span>
        @endif

        <a class="card__name" href="{{ $p['url'] }}">{{ $p['name'] }}</a>

        @if (! empty($p['spec']))
            <span class="card__spec">{{ $p['spec'] }}</span>
        @endif

        <div class="card__prices">
            <span class="card__price">{{ money($p['price']) }}</span>
            @if (! empty($p['old']))
                <span class="card__old">{{ money($p['old']) }}</span>
            @endif
        </div>

        @if (! empty($p['monthly']))
            <span class="card__monthly">{{ __('card.installment', ['amount' => $p['monthly']]) }}</span>
        @endif
    </div>

    <div class="card__foot">
        @if (empty($p['preorder']))
            <button type="button" class="card__cart" data-add aria-label="{{ __('cart.add') }}">
                <x-icon name="shopping-bag" size="16" />
            </button>
        @else
            {{-- a pre-order cannot be bought yet, so the button opens the page instead --}}
            <a class="card__cart card__cart--pre" href="{{ $p['url'] }}" title="{{ __('card.preorder') }}">
                <x-icon name="truck" size="16" />
            </a>
        @endif
        <livewire:wishlist-heart :product-id="$p['id']" :key="'wish-card-'.$p['id']" />
    </div>
</article>