@props(['category'])

@if ($category['image'])
    <img src="{{ $category['image'] }}" alt="" loading="lazy">
@else
    <svg class="caticon" aria-hidden="true"><use href="{{ \App\Support\Catalog::iconHref($category['icon'] ?? \App\Support\Catalog::DEFAULT_ICON) }}"/></svg>
@endif
