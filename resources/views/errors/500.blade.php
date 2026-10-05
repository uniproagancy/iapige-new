@extends('layouts.app', ['page' => 'error', 'title' => __('errors.500_title').' — IAPI.GE'])

@section('content')
    <main class="page">
        <div class="errpage">
            <span class="errpage__code" aria-hidden="true">500</span>
            <h1 class="errpage__title">{{ __('errors.500_title') }}</h1>
            <p class="errpage__text">{{ __('errors.500_text') }}</p>

            <div class="errpage__acts">
                <a class="btn-main" href="{{ route('home') }}">{{ __('errors.to_home') }}</a>
            </div>

            {{-- an order half-placed is exactly when somebody needs a telephone --}}
            <p class="errpage__help">
                {{ __('errors.call_us') }}
                <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone')) }}">{{ config('shop.phone') }}</a>
            </p>
        </div>
    </main>
@endsection
