<form class="news__form" wire:submit="submit" novalidate>
    @if ($done)
        <span class="news__done">{{ __('layout.news_done') }}</span>
    @else
        <label class="sr-only" for="newsEmail">{{ __('common.email') }}</label>
        <input class="news__input" id="newsEmail" type="email" wire:model="email"
               placeholder="{{ __('common.email') }}" autocomplete="email">
        <button type="submit" class="news__btn" wire:loading.attr="disabled">{{ __('layout.subscribe') }}</button>
        @error('email') <span class="ferror ferror--news">{{ $message }}</span> @enderror
    @endif
</form>
