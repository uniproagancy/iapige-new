@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'play'  => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
            'eye'   => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'eyeOff'=> '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
            'upload'=> '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'box'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
            'warn'  => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    @if ($altaFeedAvailable)
        <div class="card mb-2">
            <div class="card-body">
                <h4 class="card-title">{{ __('admin.alta_stock_link_title') }}</h4>
                <p class="text-muted">{{ __('admin.alta_stock_link_hint') }}</p>
                @if ($altaFeedUrl)
                    <label class="form-label" for="alta-stock-feed-url">{{ __('admin.alta_stock_link_label') }}</label>
                    <input id="alta-stock-feed-url" class="form-control mb-1" type="url" readonly
                           value="{{ $altaFeedUrl }}" onclick="this.select()" autocomplete="off" spellcheck="false">
                    <small class="text-muted d-block mb-1">{{ __('admin.alta_stock_link_scope') }}</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            wire:click="createAltaStockLink" wire:loading.attr="disabled"
                            wire:confirm="{{ __('admin.alta_stock_link_rotate_confirm') }}">
                        {{ __('admin.alta_stock_link_rotate') }}
                    </button>
                @else
                    <button type="button" class="btn btn-primary"
                            wire:click="createAltaStockLink" wire:loading.attr="disabled">
                        {{ __('admin.alta_stock_link_create') }}
                    </button>
                @endif
            </div>
        </div>
    @endif

    <x-admin.list-card :title="__('admin.suppliers')" :count="$suppliers->count()">
        <x-slot:actions>
            <a class="btn btn-outline-primary" href="{{ route('admin.import.upload') }}" wire:navigate>
                {!! $icon('upload', 'me-25') !!}{{ __('admin.import_upload') }}
            </a>
            <a class="btn btn-primary" href="{{ route('admin.import.run') }}" wire:navigate>
                {!! $icon('play', 'me-25') !!}{{ __('admin.run_title') }}
            </a>
        </x-slot:actions>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.priority') }}</th>
                        <th class="text-end" style="width:150px">{{ __('admin.sup_rows') }}</th>
                        <th class="text-end" style="width:150px">{{ __('admin.sup_offers') }}</th>
                        <th style="width:150px">{{ __('admin.sup_last_load') }}</th>
                        <th class="text-end" style="width:200px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $row)
                        @php($supplier = $row['model'])
                        <tr wire:key="s-{{ $supplier->id }}">
                            <td>
                                <span class="fw-bold d-block">{{ $supplier->name }}</span>
                                <small class="text-muted">{{ $supplier->code }}</small>

                                <div class="mt-25 d-flex flex-wrap gap-25">
                                    @unless ($supplier->is_active)
                                        <span class="badge rounded-pill badge-light-secondary">{{ __('admin.inactive') }}</span>
                                    @endunless

                                    @if ($row['takes_file'])
                                        <span class="badge rounded-pill badge-light-info">{{ __('admin.sup_file') }}</span>
                                    @else
                                        <span class="badge rounded-pill badge-light-primary">{{ __('admin.sup_network') }}</span>
                                    @endif

                                    @if ($row['scans'])
                                        <span class="badge rounded-pill badge-light-info">{{ __('admin.sup_scans') }}</span>
                                    @endif

                                    @if ($row['min_cost'] > 0)
                                        <span class="badge rounded-pill badge-light-secondary">
                                            {{ __('admin.sup_floor', ['amount' => money($row['min_cost'])]) }}
                                        </span>
                                    @endif

                                    @if ($row['no_stock_column'])
                                        <span class="badge rounded-pill badge-light-danger">
                                            {!! $icon('warn') !!} {{ __('admin.sup_no_stock_column') }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="text-center">{{ $supplier->priority }}</td>

                            <td class="text-end">
                                <span class="d-block">{{ number_format($row['rows']) }}</span>
                                <small class="text-muted">{{ __('admin.sup_in_stock', ['n' => number_format($row['in_stock'])]) }}</small>
                            </td>

                            <td class="text-end">
                                <a href="{{ route('admin.products', ['supplier' => $supplier->id]) }}" wire:navigate>
                                    {{ number_format($row['offers']) }}
                                </a>
                                <small class="text-muted d-block">{{ __('admin.sup_sellable', ['n' => number_format($row['sellable'])]) }}</small>
                            </td>

                            <td>
                                @if ($row['synced'])
                                    <span class="d-block">{{ \Illuminate\Support\Carbon::parse($row['synced'])->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif

                                @if ($supplier->last_run_at)
                                    <small class="text-muted">
                                        {{ __('admin.sup_last_run', ['when' => $supplier->last_run_at->diffForHumans()]) }}
                                    </small>
                                @endif
                            </td>

                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        wire:click="queue({{ $supplier->id }})"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ __('admin.sup_queue_confirm', ['name' => $supplier->name]) }}">
                                    {!! $icon('play', 'me-25') !!}{{ __('admin.sup_queue') }}
                                </button>

                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                        wire:click="toggleActive({{ $supplier->id }})"
                                        title="{{ $supplier->is_active ? __('admin.hide') : __('admin.show') }}">
                                    {!! $icon($supplier->is_active ? 'eyeOff' : 'eye') !!}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="admin-empty">{{ __('admin.nothing_here') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.list-card>
</div>
