@props(['p' => null, 'product' => null])

@php
    $p = $p ?? $product;

    $images = array_values(array_filter($p['images'] ?? []));
    $many = count($images) > 1;
@endphp

<article class="card"
         data-card
         data-product-id="{{ $p['id'] }}"
         data-product-name="{{ trim(($p['brand'] ?? '').' '.$p['name']) }}"
         data-index="0"
         data-order="{{ $p['order'] ?? 0 }}"
    <div class="card__media">
        <a class="card__media-link" href="{{ $p['url'] }}" aria-label="{{ $p['name'] }}">
            <img class="card__img" data-img src="{{ $p['thumb'] }}" alt="{{ $p['name'] }}"
                 @if ($p['thumb_srcset'] ?? '')
                     srcset="{{ $p['thumb_srcset'] }}" sizes="(max-width: 640px) 45vw, 260px"
                 @endif
                 width="260" height="260"
                 loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
        </a>

        <span class="card__fallback" aria-hidden="true">IAPI.GE</span>

        @if (! empty($p['preorder']))

            <span class="card__tag card__tag--pre">{{ __('card.preorder') }}</span>
        @elseif (! empty($p['tag']))
            <span @class(['card__tag', 'card__tag--sale' => $p['tag'] === 'sale', 'card__tag--new' => $p['tag'] === 'new'])>
                {{ __('card.tag_'.$p['tag']) }}
            </span>
        @endif

        @if (! empty($p['discount']))
            <span class="card__discount">{{ $p['discount'] }}</span>
        @endif
    </div>

    <div class="card__body">
        @if ($p['brand'])
            <span class="card__brand">{{ $p['brand'] }}</span>
        @endif

        <a class="card__name" href="{{ $p['url'] }}">{{ $p['name'] }}</a>

        @if (! empty($p['spec']))
            <span class="card__spec">{{ $p['spec'] }}</span>
        @endif

        <div class="card__prices">
            <span @class(['card__price', 'card__price--sale' => ! empty($p['old'])])>{{ money($p['price']) }}</span>
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
            <button type="button" class="card__cart" data-add>
                <x-icon name="shopping-bag" size="16" />
                <span class="card__cart-label">{{ __('cart.add') }}</span>
            </button>
        @else

            <a class="card__cart card__cart--pre" href="{{ $p['url'] }}">
                <x-icon name="truck" size="16" />
                <span class="card__cart-label">{{ __('cart.preorder') }}</span>
            </a>
        @endif
        <livewire:wishlist-heart :product-id="$p['id']" :key="'wish-card-'.$p['id']" />
    </div>
</article>
