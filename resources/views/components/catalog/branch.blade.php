
@props(['nodes', 'depth' => 3])

<div @class(['leaves', 'leaves--deep' => $depth > 3]) @style(['--branch-depth: '.($depth - 3)])>
    @foreach ($nodes as $node)
        <a class="leaf" href="{{ $node['url'] }}">
            <span class="leaf__name">{{ $node['name'] }}</span>
            <span class="leaf__count">{{ __('common.products_count', ['count' => $node['total']]) }}</span>
        </a>

        @if (! empty($node['children']))
            <x-catalog.branch :nodes="$node['children']" :depth="$depth + 1" />
        @endif
    @endforeach
</div>
