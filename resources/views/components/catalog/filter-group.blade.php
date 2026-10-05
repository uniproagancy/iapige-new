@props(['group', 'facets', 'picked'])

<div @class(['fgroup', 'is-open' => $group['open']]) style="margin-top:12px">
    <button type="button" class="fgroup__head" data-fgroup aria-expanded="{{ $group['open'] ? 'true' : 'false' }}">
        <span class="fgroup__name">{{ $group['name'] }}</span>
        <x-icon name="caret-down" size="14" class="fgroup__chev" />
    </button>
    <div class="fgroup__body">
        <div class="fopts">
            @foreach ($group['items'] as $item)
                @php
                    $n  = $facets[$group['key']][$item['code']] ?? 0;
                    $on = in_array($item['code'], (array) ($picked[$group['key']] ?? []), true);
                @endphp
                <button type="button" @class(['fopt', 'is-on' => $on, 'is-empty' => $n === 0 && ! $on])
                        wire:click="toggleFilter('{{ $group['key'] }}', '{{ $item['code'] }}')"
                        role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}">
                    <span class="fopt__box" aria-hidden="true"><x-icon name="check" size="12" /></span>
                    <span class="fopt__name">{{ $item['label'] }}</span>
                    <span class="fopt__count">{{ $n }}</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
