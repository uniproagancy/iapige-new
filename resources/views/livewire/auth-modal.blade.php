<div class="modal__box">

    <div class="auth__aside">
        <span class="auth__ghost" aria-hidden="true">%</span>
        <div class="auth__logo"><div aria-hidden="true">I</div><span>IAPI.GE</span></div>
        @php $side = $mode === 'forgot' ? 'login' : $mode; @endphp
        <div class="auth__hero">
            <h2 class="auth__heroTitle" id="authHeroTitle">{{ __("auth.{$side}_title") }}</h2>
            <p class="auth__heroText">{{ __("auth.{$side}_text") }}</p>
        </div>
        <div class="auth__perks">
            @foreach (__("auth.{$side}_perks") as $perk)
                <div class="auth__perk"><span class="auth__tick" aria-hidden="true"><x-icon name="check" size="12" /></span>{{ $perk }}</div>
            @endforeach
        </div>
    </div>

    <div class="auth__main">
        <div class="auth__top">
            <div class="auth__tabs" role="tablist">
                <button type="button" @class(['auth__tab', 'is-active' => $mode !== 'register']) wire:click="setMode('login')" role="tab" aria-selected="{{ $mode !== 'register' ? 'true' : 'false' }}">{{ __('auth.login') }}</button>
                <button type="button" @class(['auth__tab', 'is-active' => $mode === 'register']) wire:click="setMode('register')" role="tab" aria-selected="{{ $mode === 'register' ? 'true' : 'false' }}">{{ __('auth.register') }}</button>
            </div>
            <button type="button" class="drawer__close" data-close aria-label="{{ __('common.close') }}"><x-icon name="x" size="16" /></button>
        </div>

        @if ($mode === 'login')
            {{-- sign in --}}
            <form class="auth__form" wire:submit="submit" novalidate>
                <div class="field">
                    <label class="field__label" for="loginUser">{{ __('auth.login_field') }}</label>
                    <div class="field__box">
                        <input id="loginUser" type="text" wire:model="login" placeholder="name@mail.com" autocomplete="username">
                    </div>
                    @error('login') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="loginPass">{{ __('auth.password') }}</label>
                    <div class="field__box" x-data="{ show: false }">
                        <input id="loginPass" :type="show ? 'text' : 'password'" type="password" wire:model="password" placeholder="••••••••" autocomplete="current-password">
                        <button type="button" class="field__toggle" x-on:click="show = !show" x-text="show ? @js(__('auth.hide')) : @js(__('auth.show'))">{{ __('auth.show') }}</button>
                    </div>
                    @error('password') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="auth__row">
                    <label class="check"><input type="checkbox" wire:model="remember"><span class="check__box" aria-hidden="true"></span>{{ __('auth.remember') }}</label>
                    <button type="button" class="auth__forgot" wire:click="setMode('forgot')">{{ __('auth.forgot') }}</button>
                </div>

                <button type="submit" class="auth__submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submit">{{ __('auth.submit_login') }}</span>
                    <span wire:loading wire:target="submit">{{ __('auth.working') }}</span>
                </button>

                @include('components.layout.auth-socials')
                <span class="auth__note">{{ __('auth.no_account') }}</span>
            </form>

        @elseif ($mode === 'forgot')
            {{-- forgot password --}}
            <form class="auth__form" wire:submit="submit" novalidate>
                <div class="field">
                    <label class="field__label" for="forgotEmail">{{ __('auth.email') }}</label>
                    <div class="field__box">
                        <input id="forgotEmail" type="email" wire:model="email" placeholder="name@mail.com" autocomplete="email">
                    </div>
                    @error('email') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                @if ($status)
                    <div class="auth__status">{{ $status }}</div>
                @endif

                <button type="submit" class="auth__submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submit">{{ __('auth.send_reset') }}</span>
                    <span wire:loading wire:target="submit">{{ __('auth.working') }}</span>
                </button>

                <button type="button" class="auth__back" wire:click="setMode('login')">← {{ __('auth.back_to_login') }}</button>
            </form>

        @else
            {{-- register --}}
            <form class="auth__form" wire:submit="submit" novalidate>
                <div class="field">
                    <label class="field__label" for="regName">{{ __('auth.name') }}</label>
                    <div class="field__box"><input id="regName" type="text" wire:model="name" placeholder="{{ __('auth.name_ph') }}" autocomplete="name"></div>
                    @error('name') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="regEmail">{{ __('auth.email') }}</label>
                    <div class="field__box"><input id="regEmail" type="email" wire:model="email" placeholder="name@mail.com" autocomplete="email"></div>
                    @error('email') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="regPhone">{{ __('auth.phone') }}</label>
                    <div class="field__box"><input id="regPhone" type="tel" wire:model="phone" placeholder="+995 5__ __ __ __" autocomplete="tel"></div>
                    @error('phone') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="regPass">{{ __('auth.password') }}</label>
                    <div class="field__box" x-data="{ show: false }">
                        <input id="regPass" :type="show ? 'text' : 'password'" type="password" wire:model="password" placeholder="{{ __('auth.password_ph') }}" autocomplete="new-password">
                        <button type="button" class="field__toggle" x-on:click="show = !show" x-text="show ? @js(__('auth.hide')) : @js(__('auth.show'))">{{ __('auth.show') }}</button>
                    </div>
                    @error('password') <span class="ferror">{{ $message }}</span> @enderror
                </div>

                <div class="auth__row">
                    <label class="check"><input type="checkbox" wire:model="terms"><span class="check__box" aria-hidden="true"></span>{{ __('auth.terms') }}</label>
                </div>
                @error('terms') <span class="ferror">{{ $message }}</span> @enderror

                <button type="submit" class="auth__submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submit">{{ __('auth.submit_register') }}</span>
                    <span wire:loading wire:target="submit">{{ __('auth.working') }}</span>
                </button>

                @include('components.layout.auth-socials')
                <span class="auth__note">{{ __('auth.register_note') }}</span>
            </form>
        @endif
    </div>
</div>
