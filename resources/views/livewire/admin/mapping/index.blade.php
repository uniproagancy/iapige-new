@php
    /* icons are inlined so the page works whether or not feather loads */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'x'      => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'refresh'=> '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'check'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'box'    => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/>',
            'info'   => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    {{-- ------------------------------------------------------------ which side --}}
    <ul class="nav nav-pills mb-2">
        <li class="nav-item">
            <button type="button" @class(['nav-link', 'active' => $tab === 'categories'])
                    wire:click="$set('tab', 'categories')">
                {{ __('admin.categories') }}
                @if ($pending['categories'])
                    <span class="badge rounded-pill badge-light-warning ms-50">{{ $pending['categories'] }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item">
            <button type="button" @class(['nav-link', 'active' => $tab === 'attributes'])
                    wire:click="$set('tab', 'attributes')">
                {{ __('admin.attributes') }}
                @if ($pending['attributes'])
                    <span class="badge rounded-pill badge-light-warning ms-50">{{ $pending['attributes'] }}</span>
                @endif
                @if ($pending['attributes_new'])
                    <span class="badge rounded-pill badge-light-info ms-25"
                          title="{{ __('admin.new_parameters') }}">{{ $pending['attributes_new'] }}</span>
                @endif
            </button>
        </li>
    </ul>

    {{-- ------------------------------------------------------------ filters --}}
    <div class="card">
        <div class="card-body pb-1">
            <div class="row g-1">
                <div class="col-lg-5 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text">{!! $icon('search') !!}</span>
                        <input type="text" class="form-control"
                               placeholder="{{ __('admin.search') }}"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select class="form-select" wire:model.live="supplierId">
                        <option value="">{{ __('admin.all_suppliers') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-2 col-md-6">
                    <select class="form-select" wire:model.live="stateFilter">
                        <option value="">{{ __('admin.all') }}</option>
                        <option value="unmapped">{{ __('admin.not_mapped') }}</option>
                        <option value="mapped">{{ __('admin.already_mapped') }}</option>
                        @if ($tab === 'attributes')
                            {{-- mapped by the importer's guess, never confirmed --}}
                            <option value="new">{{ __('admin.new_parameters') }}</option>
                        @endif
                    </select>
                </div>

                <div class="col-lg-2 col-md-6 text-lg-end">
                    @if ($tab === 'categories' && $canRelink)
                        <button type="button" class="btn btn-outline-primary w-100"
                                wire:click="relink" wire:loading.attr="disabled" wire:target="relink">
                            {!! $icon('refresh', 'me-25') !!}
                            <span wire:loading.remove wire:target="relink">{{ __('admin.relink') }}</span>
                            <span wire:loading wire:target="relink">…</span>
                        </button>
                    @endif
                </div>
            </div>

            <small class="text-muted d-flex flex-wrap align-items-center gap-1 mt-1 pb-1">
                {{ __('admin.mapping_lead') }}
                <span class="badge badge-light-warning">{{ __('admin.pending_n', ['count' => $pending[$tab]]) }}</span>
                <span class="badge badge-light-success">{{ __('admin.mapped_n', ['count' => max(0, $rows->total() - $pending[$tab])]) }}</span>
            </small>
        </div>
    </div>

    @unless ($canRelink)
        <div class="alert alert-warning">
            <div class="alert-body d-flex align-items-center gap-1">
                {!! $icon('info') !!}
                {{ __('admin.relink_unavailable_hint') }}
            </div>
        </div>
    @endunless

    {{-- ------------------------------------------------------------ rows --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:130px">{{ __('admin.supplier') }}</th>
                        <th>{{ __('admin.external_name') }}</th>
                        <th class="text-center" style="width:90px">{{ __('admin.hits') }}</th>
                        @if ($tab === 'categories' && $canRelink)
                            <th class="text-center" style="width:110px">{{ __('admin.products') }}</th>
                        @endif
                        <th style="width:38%">{{ $tab === 'attributes' ? __('admin.attribute') : __('admin.category') }}</th>
                        <th class="text-end" style="width:120px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $current = $tab === 'attributes' ? $row->attribute_id : $row->category_id;
                            $waiting = $impact[$row->external_name] ?? 0;
                        @endphp

                        <tr wire:key="map-{{ $tab }}-{{ $row->id }}"
                            @class([
                                'table-warning-subtle' => ! $current && $waiting > 20,
                                'map-row--done'        => (bool) $current,
                            ])>
                            <td>
                                <span class="badge bg-light-secondary">{{ $row->supplier_name }}</span>
                            </td>

                            <td class="fw-bolder">
                                {{ $row->external_name }}
                                @if ($current)
                                    <span class="text-success ms-25" title="{{ __('admin.already_mapped') }}">{!! $icon('check') !!}</span>
                                @endif
                            </td>

                            <td class="text-center text-muted">{{ $row->hits }}</td>

                            @if ($tab === 'categories' && $canRelink)
                                <td class="text-center">
                                    @if ($waiting)
                                        {{-- how many products this one name is holding up --}}
                                        <span @class([
                                            'badge',
                                            'badge-light-warning' => ! $current,
                                            'badge-light-success' => (bool) $current,
                                        ])>{{ $waiting }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endif

                            <td>
                                <select class="form-select form-select-sm"
                                        wire:change="assign({{ $row->id }}, $event.target.value)">
                                    <option value="">— {{ __('admin.not_mapped') }} —</option>
                                    @foreach ($targets as $id => $label)
                                        <option value="{{ $id }}" @selected($current == $id)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="text-end text-nowrap">
                                @if ($tab === 'categories' && ! $current)
                                    {{-- the supplier's name becomes our category, in one click --}}
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                            wire:click="createAndMap({{ $row->id }})"
                                            title="{{ __('admin.create_from_name') }}">
                                        {!! $icon('plus') !!}
                                    </button>
                                @endif

                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                        data-confirm="{{ __('admin.confirm_delete') }}"
                                        data-confirm-action="forget"
                                        data-confirm-arg="{{ $row->id }}"
                                        title="{{ __('admin.delete') }}">
                                    {!! $icon('x') !!}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <span class="text-success d-block mb-25">{!! $icon('check') !!}</span>
                                {{ __('admin.all_mapped') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-body pt-1">{{ $rows->links() }}</div>
        @endif
    </div>

    @if ($tab === 'categories' && $pending['categories'] && $canRelink)
        <div class="alert alert-primary">
            <div class="alert-body d-flex align-items-center gap-1">
                {!! $icon('info') !!}
                {{ __('admin.mapping_hint') }}
            </div>
        </div>
    @endif
</div>
