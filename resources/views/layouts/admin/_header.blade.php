<nav class="header-navbar navbar navbar-expand-lg align-items-center floating-nav navbar-light navbar-shadow container-xxl">
    <div class="navbar-container d-flex content">
        <div class="bookmark-wrapper d-flex align-items-center">
            <ul class="nav navbar-nav d-xl-none">
                <li class="nav-item">
                    <a class="nav-link menu-toggle" href="#"><i class="ficon" data-feather="menu"></i></a>
                </li>
            </ul>
            <span class="text-muted d-none d-lg-inline">{{ $title ?? __('admin.dashboard') }}</span>
        </div>

        <ul class="nav navbar-nav align-items-center ms-auto">
            {{-- dark / light --}}
            <li class="nav-item d-none d-lg-block">
                <a class="nav-link nav-link-style" href="#">
                    <i class="ficon" data-feather="moon"></i>
                </a>
            </li>

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
