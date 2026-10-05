{{--
    Structured data, as one @graph.

    The shop and the site are described on every page so that cross-references
    by @id always resolve; the page then adds whatever it is actually about —
    a product, a listing, a breadcrumb trail.
--}}
@php
    $graph = \App\Support\Seo::graph(array_merge(
        [\App\Support\Seo::organization(), \App\Support\Seo::website()],
        $seo['schema'],
    ));
@endphp

@if ($graph)
    <script type="application/ld+json">{!! $graph !!}</script>
@endif
