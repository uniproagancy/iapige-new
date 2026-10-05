@extends('layouts.app', ['page' => 'error', 'title' => __('errors.404_title').' — IAPI.GE'])

@section('content')
    <main class="page">
        <div class="errpage">
            <span class="errpage__code" aria-hidden="true">404</span>
            <h1 class="errpage__title">{{ __('errors.404_title') }}</h1>
            <p class="errpage__text">{{ __('errors.404_text') }}</p>

            {{-- a dead end is where a shop offers a way on, not an apology --}}
            <div class="errpage__acts">
                <a class="btn-main" href="{{ route('catalog') }}">{{ __('errors.to_catalog') }}</a>
                <a class="btn-ghost" href="{{ route('home') }}">{{ __('errors.to_home') }}</a>
            </div>

            <p class="errpage__help">
                {{ __('errors.call_us') }}
                <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone')) }}">{{ config('shop.phone') }}</a>
            </p>
        </div>
    </main>
@endsection
