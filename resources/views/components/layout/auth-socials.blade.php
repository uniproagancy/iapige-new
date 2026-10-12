@php
    /*
     | Only what is actually configured.
     |
     | All three used to be plain buttons with nothing behind them, so the
     | shop offered three ways in and had none. A provider with no client id
     | is not offered at all, which is also what keeps a half-finished setup
     | from reaching the visitor.
     |
     | Apple is gone rather than left as a third: it needs a paid developer
     | account and a signing key, and it can come back the day those exist.
     */
    $providers = collect(['google' => 'Google', 'facebook' => 'Facebook'])
        ->filter(fn ($label, $provider) => filled(config("services.{$provider}.client_id")));
@endphp

@if ($providers->isNotEmpty())
    <div class="auth__or">{{ __('auth.or') }}</div>

    <div class="auth__socials">
        @foreach ($providers as $provider => $label)
            <a class="auth__social auth__social--{{ $provider }}"
               href="{{ route('social.redirect', $provider) }}">
                <x-icon name="{{ $provider }}-logo" />{{ $label }}
            </a>
        @endforeach
    </div>
@endif
