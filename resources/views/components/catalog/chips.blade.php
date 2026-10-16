
@props(['nodes', 'depth' => 3])

@foreach ($nodes as $node)
    <a @class(['cat-chip', 'cat-chip--deep' => $depth > 3]) href="{{ $node['url'] }}">{{ $node['name'] }}</a>

    @if (! empty($node['children']))
        <x-catalog.chips :nodes="$node['children']" :depth="$depth + 1" />
    @endif
@endforeach
