@props(['items', 'picked', 'total', 'old', 'count'])

<section class="bundle" aria-labelledby="bundleTitle">
    <div class="bundle__head">
        <h2 class="bundle__title" id="bundleTitle">{{ __('product.bundle') }}</h2>
        <button type="button" class="bundle__add"><span aria-hidden="true">+</span>{{ __('product.bundle_add') }}</button>
    </div>

    <div class="bundle__box">
        @foreach ($items as $b)
            <div @class(['brow', 'is-on' => in_array((int) $b['id'], $picked, true), 'is-off' => ! in_array((int) $b['id'], $picked, true)])
                 wire:key="bundle-{{ $b['id'] }}">
                <button type="button" class="brow__check" role="checkbox"
                        @unless(! empty($b['fixed'])) wire:click="toggleBundle({{ $b['id'] }})" @endunless
                        aria-checked="{{ in_array((int) $b['id'], $picked, true) ? 'true' : 'false' }}"
                        aria-label="{{ $b['brand'] }} {{ $b['name'] }}" @disabled(! empty($b['fixed']))>
                    <x-icon name="check" size="12" />
                </button>
                <img class="brow__img" src="{{ $b['thumb'] }}" alt="" loading="lazy">
                <span class="brow__body">
                    <a class="brow__name" href="{{ $b['url'] }}">{{ $b['brand'] }} {{ $b['name'] }}</a>
                    <span class="brow__prices">
                        <span @class(['brow__price', 'brow__price--sale' => $b['old']])>{{ money($b['price']) }}</span>
                        @if ($b['old'])
                            <span class="brow__old">{{ money($b['old']) }}</span>
                        @endif
                    </span>
                </span>
                @empty($b['fixed'])
                    <button type="button" class="brow__x" wire:click="toggleBundle({{ $b['id'] }})" aria-label="{{ __('product.remove') }}">✕</button>
                @endempty
            </div>
        @endforeach

        <div class="bundle__foot">
            <div class="bundle__meta">
                <span class="bundle__count">{!! __('product.bundle_count', ['count' => '<span>'.$count.'</span>']) !!}</span>
                <div class="bundle__sums">
                    <span class="bundle__total">{{ money($total) }}</span>
                    <span class="bundle__old">{{ money($old) }}</span>
                </div>
            </div>
            <button type="button" class="bundle__buy" wire:click="addBundle" wire:loading.attr="disabled">{{ __('product.bundle_buy') }}</button>
        </div>
    </div>
</section>
