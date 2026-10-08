{{--
    navbar-dark, because the layout is.

    The body carries dark-layout and this bar carried navbar-light, so a white
    strip floated above a dark page with pale-grey text on it.
--}}
<nav class="header-navbar navbar navbar-expand-lg align-items-center floating-nav navbar-dark navbar-shadow container-xxl">
    <div class="navbar-container d-flex content">
        <div class="bookmark-wrapper d-flex align-items-center">
            <ul class="nav navbar-nav d-xl-none">
                <li class="nav-item">
                    <a class="nav-link menu-toggle" href="#"><i class="ficon" data-feather="menu"></i></a>
                </li>
            </ul>
            {{--
                The title is not repeated here. The content header below says
                it already, in a bigger type and with its breadcrumbs, so the
                page announced itself twice within a hundred pixels.
            --}}
            <a class="navbar-brand d-none d-lg-flex align-items-center gap-1 py-0"
               href="{{ route('home') }}" target="_blank" rel="noopener">
                <i class="ficon" data-feather="external-link"></i>
                <span class="fw-bolder">{{ __('admin.view_site') }}</span>
            </a>
        </div>

        <ul class="nav navbar-nav align-items-center ms-auto">
            {{--
                The template's light/dark switch used to sit here. Nothing
                listened to it — the admin is dark either way — so it was a
                button that did nothing but invite a click.
            --}}
            {{-- who is signed in --}}
            <li class="nav-item dropdown dropdown-user">
                <a class="nav-link dropdown-toggle dropdown-user-link" href="#" data-bs-toggle="dropdown">
                    <div class="user-nav d-sm-flex d-none">
                        <span class="user-name fw-bolder">{{ auth()->user()?->name }}</span>
                        <span class="user-status">{{ __('admin.role_admin') }}</span>
                    </div>
                    <span class="avatar">
                        <span class="avatar-content">{{ auth()->user()?->initials() }}</span>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="{{ route('account') }}">
                        <i class="me-50" data-feather="user"></i> {{ __('account.title') }}
                    </a>
                    <div class="dropdown-divider"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="me-50" data-feather="power"></i> {{ __('account.sign_out') }}
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</nav>
