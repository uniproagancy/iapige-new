@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search'  => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'x'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'arrow'   => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'eye'     => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'phone'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'cart'    => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
            'money'   => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'pin'     => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'undo'    => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
            'link'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'check'   => '<polyline points="20 6 9 17 4 12"/>',
            'trash'   => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'file'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = [
        'new'       => 'primary',
        'viewed'    => 'warning',
        'completed' => 'success',
        'cancelled' => 'secondary',
        'noanswer' => 'danger',
        'calllater' => 'danger',
    ];

    /* only the states an operator still has to act on are tinted */
    $rowTint = [
        'new'    => 'adm-row-new',
        'viewed' => 'adm-row-viewed',
    ];
@endphp

<div>
    <div class="d-flex flex-wrap gap-1 mb-2">
        @foreach ($statuses as $key => $label)
            @continue (! ($counts[$key] ?? 0))
            @continue (in_array($key, \App\Livewire\Admin\Orders\Index::ARCHIVED, true) && $status !== $key)
            <button type="button"
                    @class(['btn btn-sm',
                        'btn-'.($tone[$key] ?? 'secondary') => $status === $key,
                        'btn-outline-'.($tone[$key] ?? 'secondary') => $status !== $key])
                    wire:click="$set('status', '{{ $status === $key ? '' : $key }}')">
                {{ $label }}
                <span class="badge bg-white text-dark ms-50">{{ $counts[$key] }}</span>
            </button>
        @endforeach

        @if ($archived)
            <button type="button"
                    @class(['btn btn-sm',
                        'btn-secondary' => $status === 'archive',
                        'btn-outline-secondary' => $status !== 'archive'])
                    wire:click="$set('status', '{{ $status === 'archive' ? '' : 'archive' }}')">
                {{ __('admin.order_archive') }}
                <span class="badge bg-white text-dark ms-50">{{ $archived }}</span>
            </button>
        @endif
    </div>

    @if ($selected)
        <div class="card border-primary">
            <div class="card-body py-1 d-flex flex-wrap align-items-center gap-1">
                <span class="fw-bolder">{{ __('admin.n_selected', ['count' => count($selected)]) }}</span>

                <button type="button" class="btn btn-sm btn-primary" wire:click="bulkAdvance">
                    {!! $icon('arrow', 'me-25') !!}{{ __('admin.advance') }}
                </button>

                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary ms-auto"
                        wire:click="$set('selected', [])"
                        title="{{ __('admin.reset') }}">
                    {!! $icon('x') !!}
                </button>
            </div>
        </div>
    @endif

    <x-admin.list-card :title="__('admin.orders')" :count="$totals['orders']">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('admin.orders.create') }}" wire:navigate>
                {!! $icon('cart', 'me-25') !!}{{ __('admin.order_create') }}
            </a>
        </x-slot:actions>

        <x-slot:filters>
            <div class="col-lg-4 col-md-6">
                <label class="form-label">{{ __('admin.search') }}</label>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{!! $icon('search') !!}</span>
                    <input type="text" class="form-control"
                           placeholder="{{ __('admin.search_orders') }}"
                           wire:model.live.debounce.400ms="search">
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">{{ __('admin.status') }}</label>
                <select class="form-select" wire:model.live="status">
                    <option value="">{{ __('admin.any_state') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach

                    @if ($archived)
                        <option value="archive">{{ __('admin.order_archive') }}</option>
                    @endif
                </select>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">{{ __('admin.payment') }}</label>
                <select class="form-select" wire:model.live="payment">
                    <option value="">{{ __('admin.any_payment') }}</option>
                    @foreach ($methods as $method)
                        <option value="{{ $method->code }}">{{ $method->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">{{ __('admin.payment_state') }}</label>
                <select class="form-select" wire:model.live="paid">
                    <option value="">{{ __('admin.any_payment_state') }}</option>
                    <option value="yes">{{ __('admin.paid') }}</option>
                    <option value="no">{{ __('admin.unpaid') }}</option>
                </select>
            </div>

            <div class="col-lg-4 col-md-6">
                <label class="form-label">{{ __('admin.period') }}</label>
                <div class="d-flex align-items-center gap-1">
                    <input type="date" class="form-control" wire:model.live="from">
                    <span class="text-muted">—</span>
                    <input type="date" class="form-control" wire:model.live="to">
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">{{ __('admin.sort_by') }}</label>
                <select class="form-select" wire:model.live="sort">
                    <option value="new">{{ __('admin.sort_new') }}</option>
                    <option value="old">{{ __('admin.sort_old') }}</option>
                    <option value="total-desc">{{ __('admin.sort_total_desc') }}</option>
                    <option value="total-asc">{{ __('admin.sort_total_asc') }}</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label class="form-label">{{ __('admin.per_page') }}</label>
                <select class="form-select" wire:model.live="perPage">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            @if ($this->hasFilters())
                <div class="col-lg-2 col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary w-100" wire:click="resetFilters">
                        {!! $icon('x', 'me-25') !!}{{ __('admin.reset') }}
                    </button>
                </div>
            @endif

            <div class="col-12 d-flex flex-wrap align-items-center gap-2 text-muted small">
                <span class="d-flex align-items-center gap-25">
                    {!! $icon('cart') !!} {{ __('admin.orders_n', ['count' => $totals['orders']]) }}
                </span>
                <span class="d-flex align-items-center gap-25">
                    {!! $icon('money') !!} {{ money($totals['revenue']) }}
                </span>
            </div>
        </x-slot:filters>

        <div class="table-responsive">
            <table class="table table-hover adm-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:36px"></th>
                        <th class="text-nowrap" style="width:1%">{{ __('admin.order_number') }}</th>
                        <th class="text-nowrap" style="width:1%">{{ __('admin.customer') }}</th>
                        <th class="text-nowrap">{{ __('admin.payment_method') }}</th>
                        <th class="text-end text-nowrap" style="width:1%">{{ __('admin.total') }}</th>
                        <th class="text-center text-nowrap" style="width:1%">{{ __('admin.payment') }}</th>
                        <th class="text-center text-nowrap" style="width:1%">{{ __('admin.status') }}</th>
                        <th class="text-end" style="width:1%">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="o-{{ $order->id }}"
                            @class([
                                $rowTint[$order->status] ?? '',
                                'table-active' => in_array($order->id, $selected),
                            ])>
                            <td>
                                <input type="checkbox" class="form-check-input"
                                       value="{{ $order->id }}" wire:model.live="selected">
                            </td>

                            <td class="text-nowrap">
                                <a class="fw-bolder text-body" href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                                    #{{ $order->number }}
                                </a>
                                <small class="d-block text-muted">{{ $order->created_at->format('d.m.Y H:i') }}</small>
                            </td>

                            <td>
                                <span class="fw-bolder d-block">{{ $order->name }}</span>
                            </td>

                            <td class="text-nowrap">
                                <span class="adm-chip">
                                    {{ $methods->firstWhere('code', $order->payment)?->name ?: ($order->payment ?: '—') }}
                                </span>
                            </td>

                            <td class="text-end fw-bolder text-nowrap">{{ money($order->total) }}</td>

                            <td class="text-center">
                                @if ($order->is_paid)
                                    <span class="badge rounded-pill badge-light-success">{{ __('admin.paid') }}</span>
                                @else
                                    <span class="badge rounded-pill badge-light-danger">{{ __('admin.unpaid') }}</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge rounded-pill badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                                    {{ $statuses[$order->status] ?? $order->status }}
                                </span>
                            </td>

                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-icon btn-outline-secondary"
                                   href="{{ route('admin.orders.show', $order) }}"
                                   wire:navigate
                                   title="{{ __('admin.open') }}">
                                    {!! $icon('eye') !!}
                                </a>

                                @if (isset($awaiting[$order->id]))
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-primary ms-25"
                                            wire:click="recheckPayment({{ $order->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="recheckPayment({{ $order->id }})"
                                            title="{{ __('admin.pay_ask_bank') }}">
                                        {!! $icon('refresh') !!}
                                    </button>
                                @endif

                                @if ($canDelete)
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger ms-25"
                                            wire:click="deleteOrder({{ $order->id }})"
                                            wire:confirm="{{ __('admin.order_delete_confirm', ['number' => $order->number]) }}"
                                            title="{{ __('admin.order_delete') }}">
                                        {!! $icon('trash') !!}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('cart') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="card-body pt-1">{{ $orders->links() }}</div>
        @endif
    </x-admin.list-card>
</div>
