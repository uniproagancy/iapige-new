<div class="callback">
    @if ($sent)
        {{-- the answer to a sent form is what happens next, not a tick --}}
        <div class="callback__done">
            <span class="callback__tick" aria-hidden="true">
                <x-icon name="check" size="16" />
            </span>
            <span>
                <b>{{ __('product.callback_sent') }}</b>
                <span class="callback__when">{{ __('product.callback_when') }}</span>
            </span>
        </div>

        <button type="button" class="callback__again" wire:click="again">
            {{ __('product.callback_again') }}
        </button>
    @else
        <form wire:submit="send">
            <h3 class="callback__title">{{ __('product.callback_title') }}</h3>
            <p class="callback__text">{{ __('product.callback_text') }}</p>

            <div class="callback__row">
                <input type="text" class="callback__input @error('name') is-bad @enderror"
                       placeholder="{{ __('common.name') }}"
                       autocomplete="name" wire:model="name">
                @error('name') <span class="ferror">{{ $message }}</span> @enderror
            </div>

            <div class="callback__row">
                <input type="tel" class="callback__input @error('phone') is-bad @enderror"
                       placeholder="+995 5__ __ __ __"
                       autocomplete="tel" wire:model="phone">
                @error('phone') <span class="ferror">{{ $message }}</span> @enderror
            </div>

            <div class="callback__row">
                <textarea class="callback__input" rows="2"
                          placeholder="{{ __('product.callback_comment') }}"
                          wire:model="comment"></textarea>
                @error('comment') <span class="ferror">{{ $message }}</span> @enderror
            </div>

            <button type="submit" class="callback__btn" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">{{ __('product.callback_send') }}</span>
                <span wire:loading wire:target="send">{{ __('common.wait') }}</span>
            </button>
        </form>
    @endif
</div>
