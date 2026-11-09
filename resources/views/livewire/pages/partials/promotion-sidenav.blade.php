{{-- A promotion's departments, where the catalog lists a shelf's sub-shelves.
     Choosing one stays on the promotion; the catalog's would leave it. --}}
<nav class="sidenav" aria-label="{{ __('catalog.categories') }}">
    <a class="sidenav__back" href="{{ route('promotions') }}">
        <x-icon name="caret-left" size="14" />
        {{ __('promo.all') }}
    </a>

    <div class="catlist">
        <button type="button" @class(['catnav', 'is-on' => ! $categoryId])
                wire:click="chooseCategory(null)">
            <span class="catnav__name">{{ __('promo.every_department') }}</span>
            <span class="catnav__count">{{ $onOffer }}</span>
        </button>

        @foreach ($departments as $department)
            <button type="button" @class(['catnav', 'is-on' => $categoryId === $department['id']])
                    wire:click="chooseCategory({{ $department['id'] }})"
                    wire:key="nav-d-{{ $department['id'] }}">
                <span class="catnav__name">{{ $department['name'] }}</span>
                <span class="catnav__count">{{ $department['total'] }}</span>
            </button>
        @endforeach
    </div>
</nav>
