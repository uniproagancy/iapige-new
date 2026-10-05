@php use Mcamara\LaravelLocalization\Facades\LaravelLocalization; @endphp

<div class="topbar">
    <nav class="topbar__links" aria-label="{{ __('layout.services') }}">
        <a href="{{ route('contact') }}">{{ __('nav.contact') }}</a>
        <a href="{{ route('info', 'installments') }}">{{ __('layout.installments') }}</a>
        <a href="{{ route('info', 'warranty') }}">{{ __('layout.warranty') }}</a>
    </nav>
    <div class="topbar__right">
        <a class="topbar__phone" href="tel:+995322121028">0322 12 10 28</a>

        {{-- languages come from the `languages` table; the default one has no URL prefix --}}
        <div class="lang" role="group" aria-label="{{ __('layout.language') }}">
            @foreach (LaravelLocalization::getLocalesOrder() as $code => $locale)
                <a href="{{ LaravelLocalization::getLocalizedURL($code, null, [], true) }}"
                   hreflang="{{ $code }}" lang="{{ $code }}"
                   title="{{ $locale['native'] }}"
                   @class(['is-active' => $code === app()->getLocale()])
                   @if($code === app()->getLocale()) aria-current="true" @endif>{{ $locale['short'] ?? $locale['native'] }}</a>
            @endforeach
        </div>
    </div>
</div>
