<main class="page page--narrow">
    <div class="pagehead">
        <h1 class="pagehead__title">
            <span class="pagehead__bar" aria-hidden="true"></span>
            {{ __('account.title') }}
        </h1>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="pill">{{ __('account.sign_out') }}</button>
        </form>
    </div>

    <div class="account">
        <div class="account__card">
            <div class="account__who">
                <span class="account__avatar" aria-hidden="true">{{ $user->initials() }}</span>
                <span>
                    <span class="account__name">{{ $user->name }}</span>
                    <span class="account__meta">{{ $user->email }}</span>
                </span>
            </div>

            @if ($saved)
                <div class="auth__status">{{ $saved }}</div>
            @endif

            <form class="auth__form" wire:submit="saveProfile" novalidate>
                <div class="field">
                    <label class="field__label" for="aName">{{ __('auth.name') }}</label>
                    <div class="field__box"><input id="aName" type="text" wire:model="name" autocomplete="name"></div>
                    @error('name') <span class="ferror">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="aEmail">{{ __('auth.email') }}</label>
                    <div class="field__box"><input id="aEmail" type="email" wire:model="email" autocomplete="email"></div>
                    @error('email') <span class="ferror">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="aPhone">{{ __('auth.phone') }}</label>
                    <div class="field__box"><input id="aPhone" type="tel" wire:model="phone" autocomplete="tel"></div>
                    @error('phone') <span class="ferror">{{ $message }}</span> @enderror
                </div>
                <button type="submit" class="auth__submit" wire:loading.attr="disabled">{{ __('account.save') }}</button>
            </form>
        </div>

        <div class="account__card">
            <h2 class="account__section">{{ __('account.password') }}</h2>
            <form class="auth__form" wire:submit="savePassword" novalidate>
                <div class="field">
                    <label class="field__label" for="cPass">{{ __('account.current_password') }}</label>
                    <div class="field__box"><input id="cPass" type="password" wire:model="current_password" autocomplete="current-password"></div>
                    @error('current_password') <span class="ferror">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="nPass">{{ __('auth.new_password') }}</label>
                    <div class="field__box"><input id="nPass" type="password" wire:model="password" autocomplete="new-password"></div>
                    @error('password') <span class="ferror">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="nPass2">{{ __('auth.repeat_password') }}</label>
                    <div class="field__box"><input id="nPass2" type="password" wire:model="password_confirmation" autocomplete="new-password"></div>
                </div>
                <button type="submit" class="auth__submit" wire:loading.attr="disabled">{{ __('account.save_password') }}</button>
            </form>
        </div>
    </div>
</main>
