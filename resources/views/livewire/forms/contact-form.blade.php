<form class="cform" wire:submit="submit" novalidate>
    <h2 class="cform__t">{{ __('info.write') }}</h2>

    <div class="cform__row">
        <div>
            <label class="sr-only" for="fName">{{ __('common.name') }}</label>
            <input class="finput" id="fName" type="text" wire:model="name" placeholder="{{ __('common.name') }}" autocomplete="name">
            @error('name') <span class="ferror">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="sr-only" for="fPhone">{{ __('common.phone') }}</label>
            <input class="finput" id="fPhone" type="tel" wire:model="phone" placeholder="+995 5__ __ __ __" autocomplete="tel">
            @error('phone') <span class="ferror">{{ $message }}</span> @enderror
        </div>
    </div>

    <div class="topics" role="group" aria-label="{{ __('info.topic') }}">
        @foreach ($topics as $item)
            <button type="button" @class(['topic', 'is-on' => $item === $topic])
                    wire:click="chooseTopic('{{ $item }}')" wire:key="topic-{{ $loop->index }}"
                    aria-pressed="{{ $item === $topic ? 'true' : 'false' }}">{{ $item }}</button>
        @endforeach
    </div>

    <label class="sr-only" for="fMsg">{{ __('common.message') }}</label>
    <textarea class="ftext" id="fMsg" rows="4" wire:model="message" placeholder="{{ __('info.message_placeholder') }}"></textarea>
    @error('message') <span class="ferror">{{ $message }}</span> @enderror

    <button type="submit" @class(['fsend', 'is-sent' => $sent]) wire:loading.attr="disabled">
        @if ($sent)
            <x-icon name="check" size="18" />{{ __('info.sent') }}
        @else
            {{ __('common.send') }}
        @endif
    </button>
</form>
