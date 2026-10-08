@props(['p' => null, 'product' => null])

@php
    $p = $p ?? $product;

    $payload = \App\Support\Catalog::cartPayload($p);
    $json = json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

    /*
     * The gallery the card can page through. app.js reads it off the article,
     * swaps the picture and moves the dots — all of which was already written
     * and had nothing to drive it, because the markup never carried the images.
     */
    $images = array_values(array_filter($p['images'] ?? []));
    $many = count($images) > 1;
@endphp

<article class="card"
         data-card
         data-product-id="{{ $p['id'] }}"
         data-product-name="{{ trim(($p['brand'] ?? '').' '.$p['name']) }}"
         data-index="0"
         data-order="{{ $p['order'] ?? 0 }}"
         @if ($many) data-images="{{ json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" @endif>
    {{--
        A div, not a link: the arrows and dots sit on top of the picture, and a
        button inside an anchor is invalid markup whose clicks the anchor takes
        for itself. The link covers the picture alone.
    --}}
    <div class="card__media">
        <a class="card__media-link" href="{{ $p['url'] }}" aria-label="{{ $p['name'] }}">
            <img class="card__img" data-img src="{{ $p['thumb'] }}" alt="{{ $p['name'] }}"
                 loading="lazy" onerror="this.classList.add('is-broken')">
        </a>

        {{-- the suppliers' photographs fail often enough to be worth a shape --}}
        <span class="card__fallback" aria-hidden="true">IAPI.GE</span>

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

        @if ($many)
            <button type="button" class="card__arrow card__arrow--prev" data-img-prev
                    aria-label="{{ __('card.image_prev') }}">
                <x-icon name="caret-left" size="14" />
            </button>
            <button type="button" class="card__arrow card__arrow--next" data-img-next
                    aria-label="{{ __('card.image_next') }}">
                <x-icon name="caret-right" size="14" />
            </button>

            <div class="card__dots">
                @foreach ($images as $i => $image)
                    <button type="button" @class(['card__dot', 'is-active' => $i === 0])
                            data-img-go="{{ $i }}"
                            aria-label="{{ __('card.image_n', ['n' => $i + 1]) }}"></button>
                @endforeach
            </div>
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
            {{-- a pre-order cannot be bought yet, so the button opens the page instead --}}
            <a class="card__cart card__cart--pre" href="{{ $p['url'] }}">
                <x-icon name="truck" size="16" />
                <span class="card__cart-label">{{ __('cart.preorder') }}</span>
            </a>
        @endif
        <livewire:wishlist-heart :product-id="$p['id']" :key="'wish-card-'.$p['id']" />
    </div>
</article>
