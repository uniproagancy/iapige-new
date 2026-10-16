@props([
    'colors' => [],
    'configs' => [],
])

@if ($colors || $configs)
    <div class="opts">
        @if ($colors)
            <div class="opt">
                <span class="opt__label">{{ __('product.color') }}:
                    <b>{{ collect($colors)->firstWhere('current', true)['label'] ?? '' }}</b>
                </span>

                <div class="colors" role="group" aria-label="{{ __('product.color') }}">
                    @foreach ($colors as $c)
                        @if (! empty($c['current']) || empty($c['url']))
                            <span class="color is-on"
                                  style="background:{{ $c['hex'] ?? '#ccc' }}"
                                  title="{{ $c['label'] }}"
                                  aria-current="true"
                                  aria-label="{{ $c['label'] }}"></span>
                        @else
                            <a class="color" href="{{ $c['url'] }}"
                               style="background:{{ $c['hex'] ?? '#ccc' }}"
                               title="{{ $c['label'] }}"
                               aria-label="{{ $c['label'] }}"></a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        @if ($configs)
            <div class="opt">
                <span class="opt__label">{{ __('product.memory') }}:
                    <b>{{ collect($configs)->firstWhere('current', true)['label'] ?? '' }}</b>
                </span>

                <div class="cfg" role="group" aria-label="{{ __('product.memory') }}">
                    @foreach ($configs as $item)
                        @if (! empty($item['current']) || empty($item['url']))
                            <span class="cfg__item is-on" aria-current="true">{{ $item['label'] }}</span>
                        @else
                            <a class="cfg__item" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
