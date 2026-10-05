@extends('layouts.app')

@section('title', __('catalog.index_title').' — ELIO')
@section('description', __('catalog.index_lead'))
@section('page', 'catalog')
@section('nav', 'catalog')
@section('tab', 'catalog')

@push('styles')
    @vite('resources/css/pages/catalog.css')
@endpush

@section('content')
    <x-page-bar :back="route('home')" :title="__('catalog.index_title')" heart />

    <main class="page">
        <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
            <a href="{{ route('home') }}">{{ __('common.home') }}</a>
            <x-icon name="caret-right" size="12" />
            <b>{{ __('catalog.index_title') }}</b>
        </nav>

        <div class="page__head">
            <h1 class="page__title">
                <span class="page__bar" aria-hidden="true"></span>
                {{ __('catalog.index_title') }}
            </h1>
            <span class="page__count">{{ __('catalog.index_lead') }}</span>
        </div>

        <div class="catgrid">
            @foreach ($categories as $category)
                <article class="catcard">
                    <a class="catcard__media" href="{{ $category['url'] }}" aria-label="{{ $category['name'] }}">
                        <img src="{{ $category['image'] }}" alt="" loading="lazy">
                        <span class="catcard__fallback">{{ $category['name'] }}</span>
                    </a>

                    <div class="catcard__body">
                        <div class="catcard__head">
                            <h2 class="catcard__name">
                                <a href="{{ $category['url'] }}">{{ $category['name'] }}</a>
                            </h2>
                            <span class="catcard__count">{{ __('common.products_count', ['count' => $category['total']]) }}</span>
                        </div>

                        @if ($category['subs'])
                            <ul class="catcard__subs">
                                @foreach (array_slice($category['subs'], 0, 5) as $sub)
                                    <li>
                                        <a href="{{ $sub['url'] }}">
                                            <span class="catcard__sub">{{ $sub['name'] }}</span>
                                            <span class="catcard__subcount">{{ $sub['total'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a class="catcard__cta" href="{{ $category['url'] }}">{{ __('common.all') }}</a>
                    </div>
                </article>
            @endforeach
        </div>
    </main>
@endsection
