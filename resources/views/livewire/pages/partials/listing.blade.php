{{-- The filters down the side and the products beside them: the catalog
     draws this for a shelf, a promotion for what is on offer. Only the
     navigation at the top of the side differs, and is passed in. --}}
<div class="layout">

    <aside class="filters" id="filters" aria-label="{{ __('catalog.filters') }}">
        <span class="filters__grip" aria-hidden="true"></span>

        <button type="button" class="filters__close" data-close
                aria-label="{{ __('common.close') }}">
            <x-icon name="x" size="16" />
        </button>

        @include($sidenav)

        <div class="filters__head">
            <h2 class="filters__title">{{ __('catalog.filters') }}</h2>
            <button type="button" class="filters__clear" wire:click="clear">{{ __('catalog.clear') }}</button>
        </div>

        <div class="fgroup is-open">
            <button type="button" class="fgroup__head" aria-expanded="true">
                <span class="fgroup__name">{{ __('catalog.price') }}</span>
                <x-icon name="caret-down" size="14" class="fgroup__chev" />
            </button>
            <div class="fgroup__body">
                <div class="price-row">
                    <div class="price-box"><span>{{ __('catalog.from') }}</span><b>{{ money($listing['priceMin']) }}</b></div>
                    <div class="price-box"><span>{{ __('catalog.to') }}</span><b>{{ money($max ?? $listing['priceMax']) }}</b></div>
                </div>
                <label class="sr-only" for="priceRange">{{ __('catalog.price_max') }}</label>
                <input class="price-range" id="priceRange" type="range"
                       min="{{ $listing['priceMin'] }}" max="{{ $listing['priceMax'] }}" step="100"
                       wire:model.live.debounce.400ms="max">
            </div>
        </div>

        @foreach ($listing['groups'] as $group)
					<div class="fgroup is-open" style="margin-top:12px" wire:key="grp-{{ $group['key'] }}">
						<div class="fgroup__head">
							<span class="fgroup__name">{{ $group['name'] }}</span>
						</div>
						<div class="fgroup__body">
							<div class="fopts">
								@foreach ($group['items'] as $item)
									@php($on = in_array($item['code'], $picked[$group['key']] ?? [], true))
									<button type="button" @class(['fopt', 'is-on' => $on])
											wire:click="toggle('{{ $group['key'] }}', '{{ $item['code'] }}')"
											wire:key="opt-{{ $group['key'] }}-{{ $item['code'] }}"
											role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}">
										<span class="fopt__name">{{ $item['label'] }}</span>
									</button>
								@endforeach
							</div>
						</div>
					</div>
        @endforeach

        <button type="button" class="filters__apply" data-close>
            {{ __('catalog.show_count', ['count' => $total]) }}
        </button>
    </aside>

    <div class="listing">

        @if (($subcards ?? true) && $listing['subs'] && ! $sub)
            <div class="subcards">
                @foreach ($listing['subs'] as $item)
                    @if ($subsInPlace ?? false)
                        <button type="button" class="subcard" wire:click="chooseSub({{ $item['id'] }})" wire:key="subcard-{{ $item['id'] }}">
                            <span class="subcard__media">
                                <x-category-media :category="$item" />
                            </span>
                            <span class="subcard__name">{{ $item['name'] }}</span>
                            <span class="subcard__count">{{ $item['total'] }}</span>
                        </button>
                    @else
                        <a class="subcard" href="{{ $item['url'] }}" wire:key="subcard-{{ $item['id'] }}">
                            <span class="subcard__media">
                                <x-category-media :category="$item" />
                            </span>
                            <span class="subcard__name">{{ $item['name'] }}</span>
                            <span class="subcard__count">{{ $item['total'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

        <div class="listing__head">
            <h2 class="listing__title">
                {{ $sub ? collect($listing['subs'])->firstWhere('id', $sub)['name'] ?? $listing['title'] : $listing['title'] }}
            </h2>
            @if ($sub)
                <button type="button" class="listing__reset" wire:click="chooseSub({{ $sub }})">
                    <x-icon name="x" size="12" />
                    {{ $listing['title'] }}
                </button>
            @endif
        </div>

        <div class="toolbar" id="listingTop">
            <button type="button" class="btn-filter" data-open="filters">
                <x-icon name="sliders-horizontal" size="16" />
                {{ __('catalog.filters') }}
                <span class="btn-filter__badge" @if (! $active) hidden @endif>{{ $active }}</span>
            </button>

            <span class="toolbar__count">
                {!! __('catalog.found_html', ['count' => $total]) !!}
            </span>

            <div class="sortwrap">
                <label class="sr-only" for="sort">{{ __('catalog.sort_label') }}</label>
                <select class="sort" id="sort" wire:model.live="sort">
                    @foreach ($listing['sorts'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <span class="sortwrap__chev" aria-hidden="true"><x-icon name="caret-down" size="12" /></span>
            </div>
        </div>

        <div class="cat-grid" wire:loading.class="is-busy">
            @foreach ($products as $p)
                <x-product-card :product="$p" wire:key="card-{{ $p['id'] }}" />
            @endforeach
        </div>

        @if (! $total)
            <div class="empty">
                <b>{{ __('catalog.empty') }}</b>
                <span>{{ __('catalog.empty_text') }}</span>
                <button type="button" class="load-more" wire:click="clear">{{ __('catalog.clear_filters') }}</button>
            </div>
        @endif

        @if ($left > 0)
            <button type="button" class="load-more" wire:click="loadMore" wire:loading.attr="disabled">
                {{ __('catalog.load_more', ['count' => $left]) }}
            </button>
        @endif
    </div>
</div>
