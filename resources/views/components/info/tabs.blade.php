@props(['view'])

@php
    $tabs = [
        'contact' => [route('contact'), __('info.contact')],
        'about'   => [route('about'),   __('info.about')],
        'text'    => [route('info'),    __('info.text')],
    ];
@endphp

<nav {{ $attributes->class(['ptabs']) }} aria-label="{{ __('info.pages') }}">
    @foreach ($tabs as $key => [$url, $label])
        <a href="{{ $url }}" @class(['ptab', 'is-on' => $view === $key]) @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
