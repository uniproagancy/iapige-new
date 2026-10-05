@props(['subs', 'current' => null])

<div {{ $attributes->class(['chips-row']) }} data-chips>
    @foreach ($subs as $sub)
        <button type="button" @class(['chip-sub', 'is-on' => $current === $sub['id']])
                wire:click="toggleSub({{ $sub['id'] }})"
                aria-pressed="{{ $current === $sub['id'] ? 'true' : 'false' }}">{{ $sub['name'] }}</button>
    @endforeach
</div>
