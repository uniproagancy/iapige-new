@php
    use Illuminate\Support\Facades\DB;

    // badges that mean "somebody has to look at this"
    $drafts = \App\Models\Product::where('status', 'draft')->count();
    $unmapped = DB::table('supplier_category_map')->whereNull('category_id')->count();
    $newOrders = \App\Models\Order::where('status', 'new')->count();
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

                <li class="nav-item @activeMenu('admin.dashboard')">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.dashboard') }}">
                        <i data-feather="home"></i><span>{{ __('admin.dashboard') }}</span>
                    </a>
                </li>

                {{-- catalogue --}}
                <li class="dropdown nav-item @activeMenu('admin.products', 'admin.categories', 'admin.brands')" data-menu="dropdown">
                    <a class="dropdown-toggle nav-link d-flex align-items-center" href="#" data-bs-toggle="dropdown">
                        <i data-feather="package"></i><span>{{ __('admin.catalog') }}</span>
                        @if ($drafts)
                            <span class="badge rounded-pill badge-light-warning ms-50">{{ $drafts }}</span>
                        @endif
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.products') }}">
                                <i data-feather="box"></i><span>{{ __('admin.products') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.products', ['status' => 'draft']) }}">
                                <i data-feather="edit-3"></i><span>{{ __('admin.drafts') }}</span>
                                @if ($drafts)
                                    <span class="badge rounded-pill badge-light-warning ms-auto">{{ $drafts }}</span>
                                @endif
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.categories') }}">
                                <i data-feather="grid"></i><span>{{ __('admin.categories') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.brands') }}">
                                <i data-feather="award"></i><span>{{ __('admin.brands') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                {{-- orders --}}
                <li class="nav-item @activeMenu('admin.orders')">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.orders') }}">
                        <i data-feather="shopping-cart"></i><span>{{ __('admin.orders') }}</span>
                        @if ($newOrders)
                            <span class="badge rounded-pill badge-light-danger ms-50">{{ $newOrders }}</span>
                        @endif
                    </a>
                </li>

                {{-- suppliers and mapping --}}
                <li class="dropdown nav-item @activeMenu('admin.suppliers', 'admin.mapping')" data-menu="dropdown">
                    <a class="dropdown-toggle nav-link d-flex align-items-center" href="#" data-bs-toggle="dropdown">
                        <i data-feather="download-cloud"></i><span>{{ __('admin.import') }}</span>
                        @if ($unmapped)
                            <span class="badge rounded-pill badge-light-info ms-50">{{ $unmapped }}</span>
                        @endif
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.dashboard') }}">
                                <i data-feather="truck"></i><span>{{ __('admin.suppliers') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.mapping') }}">
                                <i data-feather="shuffle"></i><span>{{ __('admin.mapping') }}</span>
                                @if ($unmapped)
                                    <span class="badge rounded-pill badge-light-info ms-auto">{{ $unmapped }}</span>
                                @endif
                            </a>
                        </li>
						<li>
                            <a class="dropdown-item d-flex align-items-center" href="{{ route('admin.attributes') }}">
                                <i data-feather="sliders"></i><span>{{ __('admin.attributes') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>
				
				<li class="nav-item {{ request()->routeIs('admin.callbacks') ? 'active' : '' }}">
					<a class="d-flex align-items-center" href="{{ route('admin.callbacks') }}">
						<i data-feather="phone-call"></i>
						<span class="menu-title text-truncate">{{ __('admin.callbacks') }}</span>

						@php $cb = \App\Models\CallbackRequest::where('status', 'new')->count(); @endphp
						@if ($cb)
							<span class="badge rounded-pill badge-light-danger ms-auto me-1">{{ $cb }}</span>
						@endif
					</a>
				</li>

                {{-- promotions --}}
                <li class="nav-item @activeMenu('admin.promotions')">
                    <a class="nav-link d-flex align-items-center" href="{{ route('admin.promotions') }}">
                        <i data-feather="percent"></i><span>{{ __('admin.promotions') }}</span>
                    </a>
                </li>

                {{-- the storefront, one click away --}}
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center" href="{{ route('home') }}" target="_blank" rel="noopener">
                        <i data-feather="external-link"></i><span>{{ __('admin.view_site') }}</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
