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
                            <x-category-media :category="$category" />
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

            @include('livewire.pages.partials.listing', ['sidenav' => 'livewire.pages.partials.catalog-sidenav'])
        </main>
    @endif
</div>
