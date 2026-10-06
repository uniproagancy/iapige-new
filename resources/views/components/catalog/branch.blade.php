{{--
    The third level and everything under it.

    Calls itself, because the tree is as deep as the data: the shop is mostly
    three levels but a fourth already exists, and the menu used to stop dead at
    the third with no way to show what was below.

    Each level is indented one step and the type shrinks, so depth reads as
    depth without a separate style per level.
--}}
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
