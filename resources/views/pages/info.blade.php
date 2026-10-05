@extends('layouts.app')

@php
    $titles = ['contact' => __('info.contact'), 'about' => __('info.about'), 'text' => __('info.text')];
    $heading = $view === 'text' ? $docs[$doc]['title'] : $titles[$view];
@endphp

@section('title', $heading.' — IAPI.GE')
@section('description', __('info.description'))
@section('page', 'info')
@section('nav', $view === 'about' ? 'about' : 'contact')
@section('tab', '')

@push('styles')
    @vite('resources/css/pages/info.css')
@endpush

@section('content')
    <x-page-bar :back="route('home')" :title="$titles[$view]">
        <x-info.tabs :view="$view" />
    </x-page-bar>

    <main class="page">
        <div class="pagehead">
            <h1 class="pagehead__title">
                <span class="pagehead__bar" aria-hidden="true"></span>
                {{ $titles[$view] }}
            </h1>
            <x-info.tabs :view="$view" />
        </div>

        @switch($view)
            @case('contact')
                <x-info.contact :cards="$contactCards" :topics="$topics" />
                @break
            @case('about')
                <x-info.about :values="$values" />
                @break
            @default
                <x-info.document :docs="$docs" :active="$doc" />
        @endswitch
    </main>
@endsection

