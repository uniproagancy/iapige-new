@props(['group', 'facets', 'picked'])

{{-- the groups arrive already trimmed to what is still worth offering --}}
@if (count($group['items']))
<div @class(['fgroup', 'is-open' => $group['open']]) style="margin-top:12px">
    <button type="button" class="fgroup__head" data-fgroup aria-expanded="{{ $group['open'] ? 'true' : 'false' }}">
        <span class="fgroup__name">{{ $group['name'] }}</span>
        <x-icon name="caret-down" size="14" class="fgroup__chev" />
    </button>
    <div class="fgroup__body">
        <div class="fopts">
            @foreach ($group['items'] as $item)
                @php($on = in_array($item['code'], (array) ($picked[$group['key']] ?? []), true))
                <button type="button" @class(['fopt', 'is-on' => $on])
                        wire:click="toggleFilter('{{ $group['key'] }}', '{{ $item['code'] }}')"
                        role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}">
                    <span class="fopt__name">{{ $item['label'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif
