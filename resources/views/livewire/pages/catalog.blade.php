<div>
    @if (empty($listing))
        <x-page-bar :back="route('home')" :title="__('catalog.index_title')" heart />

        <main class="page">
            <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
                <a href="{{ route('home') }}">{{ __('common.home') }}</a>
                <x-icon name="caret-right" size="12" />
                <b>{{ __('catalog.index_title') }}</b>
            </nav>

            <div class="page__head">
                <h1 class="page__title">
                    <span class="page__bar" aria-hidden="true"></span>
                    {{ __('catalog.index_title') }}
                </h1>
                <span class="page__count">{{ __('catalog.index_lead') }}</span>
            </div>
			<div class="catgrid">
                @foreach ($categories as $category)
                    <a class="cattile" href="{{ $category['url'] }}" wire:key="cat-{{ $category['id'] }}">
                        <span class="cattile__media">
                            <img src="{{ $category['image'] }}" alt="" loading="lazy">
                        </span>
                        <span class="cattile__name">{{ $category['name'] }}</span>
                    </a>
                @endforeach
            </div>
        </main>

    @else
        <x-page-bar :back="route('catalog')" :title="$listing['title']"
                    :count="new \Illuminate\Support\HtmlString($total.' / '.$listing['total'].' '.__('common.products'))" heart>
            @if ($listing['subs'])
                <div class="chips-row">
                    @foreach ($listing['subs'] as $item)
                        <button type="button" @class(['chip-sub', 'is-on' => $sub === $item['id']])
                                wire:click="chooseSub({{ $item['id'] }})"
                                wire:key="chip-m-{{ $item['id'] }}">{{ $item['name'] }}</button>
                    @endforeach
                </div>
            @endif
        </x-page-bar>

        <main class="page">
            <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
                <a href="{{ route('home') }}">{{ __('common.home') }}</a>
                <x-icon name="caret-right" size="12" />
                <a href="{{ route('catalog') }}">{{ __('catalog.index_title') }}</a>
                @foreach ($listing['breadcrumbs'] as $crumb)
                    <x-icon name="caret-right" size="12" />
                    @if ($loop->last)
                        <b>{{ $crumb['name'] }}</b>
                    @else
                        <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
                    @endif
                @endforeach
            </nav>

            <div class="layout">

                {{-- ---------------------------------------------- sidebar --}}
                <aside class="filters" id="filters" aria-label="{{ __('catalog.filters') }}">
                    <span class="filters__grip" aria-hidden="true"></span>

                    <nav class="sidenav" aria-label="{{ __('catalog.categories') }}">
                        <a class="sidenav__back" href="{{ route('catalog') }}">
                            <x-icon name="caret-left" size="14" />
                            {{ __('common.home') }}
                        </a>

                        <div class="catlist">
                            @foreach ($listing['subs'] as $item)
                                <button type="button" @class(['catnav', 'is-on' => $sub === $item['id']])
                                        wire:click="chooseSub({{ $item['id'] }})"
                                        wire:key="nav-{{ $item['id'] }}">
                                    <span class="catnav__name">{{ $item['name'] }}</span>
                                    <x-icon name="caret-right" size="14" class="catnav__chev" />
                                </button>
                            @endforeach
                        </div>

                        <a class="catlist__all" href="{{ route('catalog') }}">
                            {{ __('catalog.see_all', ['count' => count(\App\Support\Catalog::tree())]) }}
                        </a>
                    </nav>

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
							<span class="fgroup__n">{{ count($group['items']) }}</span>
						</div>
						<div class="fgroup__body">
							<div class="fopts">
								@foreach ($group['items'] as $item)
									@php
										$n  = $facets[$group['key']][$item['code']] ?? 0;
										$on = in_array($item['code'], $picked[$group['key']] ?? [], true);
									@endphp
									<button type="button" @class(['fopt', 'is-on' => $on, 'is-empty' => $n === 0 && ! $on])
											wire:click="toggle('{{ $group['key'] }}', '{{ $item['code'] }}')"
											wire:key="opt-{{ $group['key'] }}-{{ $item['code'] }}"
											role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}">
										<span class="fopt__box" aria-hidden="true"><x-icon name="check" size="12" /></span>
										<span class="fopt__name">{{ $item['label'] }}</span>
										<span class="fopt__count">{{ $n }}</span>
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

                {{-- ---------------------------------------------- listing --}}
                <div class="listing">

                    {{-- subcategory tiles — hidden once one of them is selected --}}
                    @if ($listing['subs'] && ! $sub)
                        <div class="subcards">
                            @foreach ($listing['subs'] as $item)
                                <a class="subcard" href="{{ $item['url'] }}" wire:key="subcard-{{ $item['id'] }}">
                                    <span class="subcard__media">
                                        <img src="{{ $item['image'] }}" alt="" loading="lazy">
                                    </span>
                                    <span class="subcard__name">{{ $item['name'] }}</span>
                                    <span class="subcard__count">{{ $item['total'] }}</span>
                                </a>
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

                    <div class="toolbar">
                        <button type="button" class="btn-filter" data-open="filters">
                            <x-icon name="sliders-horizontal" size="16" />
                            {{ __('catalog.filters') }}
                            <span class="btn-filter__badge" @if (! $active) hidden @endif>{{ $active }}</span>
                        </button>

                        <span class="toolbar__count">
                            {{ __('catalog.found') }} <b>{{ $total }}</b> {{ __('common.products') }}
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
        </main>
    @endif
</div>