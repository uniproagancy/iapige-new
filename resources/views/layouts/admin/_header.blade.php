@php
    /*
     | Drawn here rather than by feather, like the menu below it.
     |
     | <i data-feather="…"> is an empty element until a bundle of ours has
     | loaded, a global of theirs is where we left it and replace() has run
     | over the DOM. Any one of those missing and this bar is a blank avatar
     | and two unlabelled gaps in a dropdown.
     */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'menu'  => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
            'user'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'power' => '<path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

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
                    <a class="nav-link menu-toggle" href="#">{!! $icon('menu') !!}</a>
                </li>
            </ul>
        </div>

        <ul class="nav navbar-nav align-items-center ms-auto">
            {{--
                The template's light/dark switch used to sit here. Nothing
                listened to it — the admin is dark either way — so it was a
                button that did nothing but invite a click.
            --}}

            {{--
                No link to the storefront here either.

                It was the last item of the menu below and has been taken out
                rather than moved up: the label is still used on the product
                screens, where "view site" means this one product on the shop,
                which is the only place it says anything.
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
                    <a class="dropdown-item d-flex align-items-center" href="{{ route('account') }}">
                        {!! $icon('user', 'me-50') !!}{{ __('account.title') }}
                    </a>
                    <div class="dropdown-divider"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item d-flex align-items-center">
                            {!! $icon('power', 'me-50') !!}{{ __('account.sign_out') }}
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</nav>
