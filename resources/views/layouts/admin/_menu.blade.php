@php
    use Illuminate\Support\Facades\DB;

    // badges that mean "somebody has to look at this"
    $drafts = \App\Models\Product::where('status', 'draft')->count();
    $unmapped = DB::table('supplier_category_map')->whereNull('category_id')->count();
    $newOrders = \App\Models\Order::where('status', 'new')->count();
    $callbacks = \App\Models\CallbackRequest::where('status', 'new')->count();

    /*
     | Drawn here rather than by feather.
     |
     | Every icon in this menu was <i data-feather="…">, which is an empty
     | element until a bundle of ours has loaded, a global of theirs is where
     | we left it, and replace() has run over the DOM. Any one of those
     | missing and the menu is a row of words with gaps in it — which is what
     | it was. A navigation bar has no business depending on that, so the
     | shapes are in the markup and cannot fail to appear.
     */
    $icon = function (string $name) {
        $paths = [
            'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'package'  => '<path d="M16.5 9.4 7.5 4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'box'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
            'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'award'    => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
            'cart'     => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
            'download' => '<polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>',
            'truck'    => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'play'     => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
            'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'shuffle'  => '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/>',
            'sliders'  => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'phone'    => '<path d="M15.05 5A5 5 0 0 1 19 8.95M15.05 1A9 9 0 0 1 23 8.94"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'percent'  => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            'chevron'  => '<polyline points="6 9 12 15 18 9"/>',
        ];

        return '<svg class="adm-ico" width="15" height="15" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    /*
     | Which item is the one you are looking at.
     |
     | This was @activeMenu(...), which is not a directive anybody registered
     | — so Blade left it alone and the rendered class attribute read
     | class="nav-item @activeMenu('admin.dashboard')". Nothing was ever
     | highlighted, and the one item that did work used routeIs() by hand.
     */
    $on = fn (...$routes) => request()->routeIs(...$routes) ? 'active' : '';

    $pill = fn (int $n, string $tone) => $n
        ? '<span class="badge rounded-pill badge-light-'.$tone.' ms-50">'.$n.'</span>'
        : '';
@endphp

<div class="horizontal-menu-wrapper">
    <div class="header-navbar navbar-expand-sm navbar navbar-horizontal floating-nav navbar-light navbar-shadow menu-border container-xxl"
         role="navigation" data-menu="menu-wrapper" data-menu-type="floating-nav">
        <div class="navbar-header">
            <ul class="nav navbar-nav flex-row">
                <li class="nav-item me-auto">
                    <a class="navbar-brand" href="{{ route('admin.dashboard') }}">
                        <h2 class="brand-text mb-0">IAPI.GE</h2>
                    </a>
                </li>
            </ul>
        </div>

        <div class="shadow-bottom"></div>

        <div class="navbar-container main-menu-content" data-menu="menu-container">
            <ul class="nav navbar-nav" id="main-menu-navigation" data-menu="menu-navigation">

                <li class="nav-item {{ $on('admin.dashboard') }}">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.dashboard') }}">
                        {!! $icon('home') !!}<span>{{ __('admin.dashboard') }}</span>
                    </a>
                </li>

                {{-- catalogue --}}
                <li class="dropdown nav-item {{ $on('admin.products', 'admin.products.*', 'admin.categories', 'admin.brands') }}"
                    data-menu="dropdown">
                    <a class="dropdown-toggle nav-link d-flex align-items-center" href="#" data-bs-toggle="dropdown">
                        {!! $icon('package') !!}<span>{{ __('admin.catalog') }}</span>
                        {!! $pill($drafts, 'warning') !!}
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.products') }}">
                                {!! $icon('box') !!}<span>{{ __('admin.products') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.products', ['status' => 'draft']) }}">
                                {!! $icon('edit') !!}<span>{{ __('admin.drafts') }}</span>
                                @if ($drafts)
                                    <span class="badge rounded-pill badge-light-warning ms-auto">{{ $drafts }}</span>
                                @endif
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.categories') }}">
                                {!! $icon('grid') !!}<span>{{ __('admin.categories') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.brands') }}">
                                {!! $icon('award') !!}<span>{{ __('admin.brands') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                {{-- orders --}}
                <li class="nav-item {{ $on('admin.orders', 'admin.orders.*') }}">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.orders') }}">
                        {!! $icon('cart') !!}<span>{{ __('admin.orders') }}</span>
                        {!! $pill($newOrders, 'danger') !!}
                    </a>
                </li>

                {{-- suppliers and mapping --}}
                <li class="dropdown nav-item {{ $on('admin.mapping', 'admin.attributes', 'admin.import.*') }}"
                    data-menu="dropdown">
                    <a class="dropdown-toggle nav-link d-flex align-items-center" href="#" data-bs-toggle="dropdown">
                        {!! $icon('download') !!}<span>{{ __('admin.import') }}</span>
                        {!! $pill($unmapped, 'info') !!}
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.dashboard') }}">
                                {!! $icon('truck') !!}<span>{{ __('admin.suppliers') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.import.run') }}">
                                {!! $icon('play') !!}<span>{{ __('admin.run_title') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.import.links') }}">
                                {!! $icon('link') !!}<span>{{ __('admin.links_title') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.import.upload') }}">
                                {!! $icon('upload') !!}<span>{{ __('admin.import_upload') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.mapping') }}">
                                {!! $icon('shuffle') !!}<span>{{ __('admin.mapping') }}</span>
                                @if ($unmapped)
                                    <span class="badge rounded-pill badge-light-info ms-auto">{{ $unmapped }}</span>
                                @endif
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.attributes') }}">
                                {!! $icon('sliders') !!}<span>{{ __('admin.attributes') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                {{-- callbacks --}}
                <li class="nav-item {{ $on('admin.callbacks') }}">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.callbacks') }}">
                        {!! $icon('phone') !!}<span>{{ __('admin.callbacks') }}</span>
                        {!! $pill($callbacks, 'danger') !!}
                    </a>
                </li>

                {{-- the home page's carousel --}}
                <li class="nav-item {{ $on('admin.slides') }}">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.slides') }}">
                        {!! $icon('image') !!}<span>{{ __('admin.slides') }}</span>
                    </a>
                </li>

                {{-- promotions --}}
                <li class="nav-item {{ $on('admin.promotions') }}">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.promotions') }}">
                        {!! $icon('percent') !!}<span>{{ __('admin.promotions') }}</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
