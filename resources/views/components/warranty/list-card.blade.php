@props([
    'title' => null,
    'count' => null,
    'actions' => null,
    'filters' => null,
])

<div class="card">
    @if ($title || $actions)
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-1">
            <h4 class="card-title mb-0">
                {{ $title }}

                @if (! is_null($count))
                    <span class="badge rounded-pill badge-light-secondary ms-50">{{ number_format((int) $count) }}</span>
                @endif
            </h4>

            @if ($actions)
                <div class="d-flex flex-wrap align-items-center gap-1">{{ $actions }}</div>
            @endif
        </div>
    @endif

    @if ($filters)
        <div class="card-body">
            <div class="row g-1">{{ $filters }}</div>
        </div>
    @endif

    {{ $slot }}
</div>
