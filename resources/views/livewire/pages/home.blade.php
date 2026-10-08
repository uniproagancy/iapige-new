<div>
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