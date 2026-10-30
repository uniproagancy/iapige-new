{{--
    On a phone the catalogue and the information pages put their own bar where
    the header is, and the header -- with the search in it -- is hidden. This
    is the same search, as a sheet those pages can open, so looking something
    up no longer means going back to the front page first.
--}}
<aside class="drawer search-sheet" id="searchSheet" hidden aria-label="{{ __('layout.search') }}">
    <div class="search-sheet__inner">
        <div class="search-sheet__head">
            <h2 class="drawer__title">{{ __('layout.search') }}</h2>
            <button type="button" class="drawer__close" data-close aria-label="{{ __('common.close') }}">
                <x-icon name="x" size="16" />
            </button>
        </div>

        <livewire:header-search input-id="searchSheetInput" />
    </div>
</aside>
