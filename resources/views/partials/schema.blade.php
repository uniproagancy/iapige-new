
@php
    $graph = \App\Support\Seo::graph(array_merge(
        [\App\Support\Seo::organization(), \App\Support\Seo::website()],
        $seo['schema'],
    ));
@endphp

@if ($graph)
    <script type="application/ld+json">{!! $graph !!}</script>
@endif
