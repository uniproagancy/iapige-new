<!DOCTYPE html>
<html class="loading dark-layout" lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-layout="dark-layout" data-textdirection="ltr" data-framework="laravel">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,user-scalable=0,minimal-ui">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ ($title ?? __('admin.dashboard')).' — ELIO' }}</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('dashboard-assets/images/ico/favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;1,400;1,500;1,600&display=swap">

    @vite(['resources/dashboard/admin.css', 'resources/dashboard/admin.js'])

    @livewireStyles
    @stack('styles')
</head>

{{-- data-asset-path stays: the template's own app.js reads its images from there --}}
<body class="horizontal-layout horizontal-menu navbar-floating footer-static @isset($blank_page) blank-page @endisset"
      data-open="hover" data-menu="horizontal-menu" data-col=""
      data-asset-path="{{ asset('dashboard-assets/') }}">

@unless (isset($blank_page))
    @include('layouts.admin._header')
    @include('layouts.admin._menu')
@endunless

{{--
    The template positions everything from these three wrappers: without them
    the navbar floats over the page and the content starts under the header.
--}}
<div class="app-content content">
    <div class="content-overlay"></div>
    <div class="header-navbar-shadow"></div>

    <div class="content-wrapper container-xxl p-0">
        @unless (isset($blank_page))
            <div class="content-header row">
                <div class="content-header-left col-md-9 col-12 mb-2">
                    <div class="row breadcrumbs-top">
                        <div class="col-12">
                            <h2 class="content-header-title float-start mb-0">{{ $title ?? __('admin.dashboard') }}</h2>
                            <div class="breadcrumb-wrapper">
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item">
                                        <a href="{{ route('admin.dashboard') }}">{{ __('admin.dashboard') }}</a>
                                    </li>
                                    @isset($title)
                                        <li class="breadcrumb-item active">{{ $title }}</li>
                                    @endisset
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- a page can push its own buttons up here --}}
                @isset($actions)
                    <div class="content-header-right text-md-end col-md-3 col-12 d-md-block d-none">
                        {{ $actions }}
                    </div>
                @endisset
            </div>
        @endunless

        <div class="content-body">
            {{ $slot }}
        </div>
    </div>
</div>

@unless (isset($blank_page))
    @include('layouts.admin._footer')
@endunless

<div class="sidenav-overlay"></div>
<div class="drag-target"></div>

<button class="btn btn-primary btn-icon scroll-top" type="button">
    <i data-feather="arrow-up"></i>
</button>

@livewireScripts
@stack('scripts')

<script>
    /* the confirm dialog in admin.js reads its button labels from here */
    window.ELIO_ADMIN = @json([
        'yes'    => __('admin.yes'),
        'cancel' => __('admin.cancel'),
    ]);
</script>
</body>
</html>
