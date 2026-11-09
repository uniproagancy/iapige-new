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
