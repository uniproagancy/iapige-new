<main class="page page--narrow">
    <div class="authpage">
        <h1 class="authpage__title">{{ __('auth.reset_title') }}</h1>
        <p class="authpage__text">{{ __('auth.reset_text') }}</p>

        <form class="auth__form" wire:submit="submit" novalidate>
            <div class="field">
                <label class="field__label" for="rEmail">{{ __('auth.email') }}</label>
                <div class="field__box"><input id="rEmail" type="email" wire:model="email" autocomplete="email"></div>
                @error('email') <span class="ferror">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label class="field__label" for="rPass">{{ __('auth.new_password') }}</label>
                <div class="field__box"><input id="rPass" type="password" wire:model="password" placeholder="{{ __('auth.password_ph') }}" autocomplete="new-password"></div>
                @error('password') <span class="ferror">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label class="field__label" for="rPass2">{{ __('auth.repeat_password') }}</label>
                <div class="field__box"><input id="rPass2" type="password" wire:model="password_confirmation" autocomplete="new-password"></div>
            </div>

            <button type="submit" class="auth__submit" wire:loading.attr="disabled">{{ __('auth.save_password') }}</button>
        </form>
    </div>
</main>
