<div>
    {{-- The slides carry their own headings; the page itself had none. --}}
    <h1 class="sr-only">{{ __('layout.title') }}</h1>
    <x-home.hero :slides="$slides" />
    <x-home.promo :deals="$deals" :campaign="$campaign" :deadline="now()->endOfWeek()->toIso8601String()" />
	<x-home.perks />
    <main class="sections" id="sections">
        @foreach ($sections as $section)
            <x-home.category-section :section="$section" />
        @endforeach
    </main>
    <x-home.brands :brands="$brands" />
</div>