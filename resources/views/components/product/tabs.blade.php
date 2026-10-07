@props(['specs' => [], 'description' => null])

@php
    $rows = collect($specs)
        ->filter(fn ($row) => filled($row[0] ?? null)
            && filled($row[1] ?? null)
            && ! in_array(trim((string) $row[1]), ['-', '—', 'N/A'], true))
        ->values();
    $hasSpecs = $rows->isNotEmpty();
    $hasDescription = filled($description);
@endphp

@if ($hasSpecs || $hasDescription)
    <section class="tabsec" aria-label="{{ __('product.details') }}" id="tabs" style="border-top: 1px solid #f1f2f4">
        @if ($hasSpecs && $hasDescription)
            <div class="tabbar-pills" role="tablist">
                <button type="button" class="tabpill is-on" data-tab="specs"
                        role="tab" aria-selected="true">{{ __('product.tab_specs') }}</button>
                <button type="button" class="tabpill" data-tab="desc"
                        role="tab" aria-selected="false">{{ __('product.tab_desc') }}</button>
            </div>
        @endif

        @if ($hasSpecs)
            <div class="tabpanel" data-tab-panel="specs">
                <div class="specgrid">
                    @foreach ($rows as [$label, $value])
                        <div class="srow">
                            <span class="srow__k">{{ $label }}</span>
                            <span class="srow__v">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($hasDescription)
            {{-- with no specifications the description is the page's only detail,
                 so it opens rather than hiding behind a tab nobody will press --}}
            <div class="tabpanel" data-tab-panel="desc" @if ($hasSpecs) hidden @endif>
                <div class="desc">
                    <div class="desc__body">
                        <h3 class="desc__title">{{ __('product.desc_title') }}</h3>
                        <div class="desc__p">{!! $description !!}</div>
                    </div>
                </div>
            </div>
        @endif
    </section>
@endif