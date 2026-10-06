@props(['tree' => \App\Support\Catalog::tree()])

<aside class="drawer catalog" id="catalog" hidden aria-label="{{ __('layout.catalog') }}">
    <div class="catalog__l1">
        <div class="drawer__head">
            <h2 class="drawer__title">{{ __('layout.catalog') }}</h2>
            <button type="button" class="drawer__close" data-close aria-label="{{ __('common.close') }}">
                <x-icon name="x" size="16" />
            </button>
        </div>

        <div class="cats" id="catsList">
            @foreach ($tree as $i => $cat)
                <div class="cat-node" data-cat-node="{{ $i }}">
                    <button type="button" @class(['cat', 'is-active' => $i === 0]) data-cat="{{ $i }}" aria-expanded="false">
                        <img class="cat__img" src="{{ $cat['image'] }}" alt="" loading="lazy">
                        <span class="cat__body">
                            <span class="cat__name">{{ $cat['name'] }}</span>
                            <span class="cat__count">{{ $cat['count'] }}</span>
                        </span>
                        <x-icon name="caret-right" size="14" class="cat__chev" />
                    </button>

                    {{-- mobile accordion --}}
                    <div class="cat__kids">
                        @foreach ($cat['subs'] as $sub)
                            <div>
                                <a class="cat-sub" href="{{ $sub['url'] }}">
                                    <span class="cat-sub__dot" aria-hidden="true"></span>
                                    <span class="cat-sub__name">{{ $sub['name'] }}</span>
                                    <span class="cat-sub__count">{{ $sub['count'] }}</span>
                                </a>

                                @if (! empty($sub['children']))
                                    <div class="cat-chips">
                                        <x-catalog.chips :nodes="$sub['children']" />
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- desktop: second level, one panel per category --}}
    <div class="catalog__l2" id="catalogL2">
        @foreach ($tree as $i => $cat)
            <div data-l2="{{ $i }}" @if ($i) hidden @endif>
                <div class="catalog__l2head">
                    <div>
                        <span class="catalog__eyebrow">{{ __('catalog.categories') }}</span>
                        <span class="catalog__cat">{{ $cat['name'] }}</span>
                    </div>
                    <a class="pill pill--red" href="{{ $cat['url'] }}">{{ __('common.all') }}</a>
                </div>

                <div class="subs">
                    @foreach ($cat['subs'] as $sub)
                        {{--
                            The second level is a block, not a link: its own link is the
                            heading, so the third level below can be clickable too — an
                            <a> inside an <a> is invalid and the inner one stops working.
                        --}}
                        <div class="sub">
                            <a class="sub__head" href="{{ $sub['url'] }}">
                                <span class="sub__name">{{ $sub['name'] }}</span>
                                <span class="sub__count">{{ __('common.products_count', ['count' => $sub['count']]) }}</span>
                            </a>

                            @if (! empty($sub['children']))
                                <x-catalog.branch :nodes="$sub['children']" />
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</aside>