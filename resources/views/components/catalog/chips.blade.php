{{--
    The mobile accordion's chip row, for the third level and below.

    Flattened rather than indented: the accordion is narrow and a nested
    indent would push deep names into one word per line. Depth shows as a
    lighter chip instead, and the order keeps the hierarchy readable.
--}}
@props(['nodes', 'depth' => 3])

@foreach ($nodes as $node)
    <a @class(['cat-chip', 'cat-chip--deep' => $depth > 3]) href="{{ $node['url'] }}">{{ $node['name'] }}</a>

    @if (! empty($node['children']))
        <x-catalog.chips :nodes="$node['children']" :depth="$depth + 1" />
    @endif
@endforeach
