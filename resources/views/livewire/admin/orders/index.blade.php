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
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = [
        'new'       => 'danger',
        'confirmed' => 'info',
        'packed'    => 'info',
        'shipped'   => 'primary',
        'completed' => 'success',
        'cancelled' => 'secondary',
        'returned'  => 'warning',
    ];
@endphp

<div>
    {{-- ------------------------------------------------------------ what is waiting --}}
    <div class="d-flex flex-wrap gap-1 mb-2">
        @foreach ($statuses as $key => $label)
            @continue (! ($counts[$key] ?? 0))
            <button type="button"
                    @class(['btn btn-sm',
                        'btn-'.($tone[$key] ?? 'secondary') => $status === $key,
                        'btn-outline-'.($tone[$key] ?? 'secondary') => $status !== $key])
                    wire:click="$set('status', '{{ $status === $key ? '' : $key }}')">
                {{ $label }}
                <span class="badge bg-white text-dark ms-50">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ filters --}}
    <div class="card">
        <div class="card-body pb-1">
            <div class="row g-1">
                <div class="col-lg-4 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text">{!! $icon('search') !!}</span>
                        <input type="text" class="form-control"
                               placeholder="{{ __('admin.search_orders') }}"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="col-lg-2 col-md-6">
                    <select class="form-select" wire:model.live="payment">
                        <option value="">{{ __('admin.any_payment') }}</option>
                        <option value="cash">{{ __('checkout.pay_cash') }}</option>
                        <option value="card">{{ __('checkout.pay_card') }}</option>
                        <option value="installment">{{ __('checkout.pay_installment') }}</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="d-flex align-items-center gap-1">
                        <input type="date" class="form-control" wire:model.live="from">
                        <span class="text-muted">—</span>
                        <input type="date" class="form-control" wire:model.live="to">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 d-flex align-items-center gap-1 justify-content-lg-end">
                    @if ($this->hasFilters())
                        <button type="button" class="btn btn-outline-secondary" wire:click="resetFilters">
                            {!! $icon('x', 'me-25') !!}{{ __('admin.reset') }}
                        </button>
                    @endif

                    <select class="form-select" style="width:auto" wire:model.live="perPage">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>

            {{-- the totals follow the filters, so they always describe what is on screen --}}
            <div class="d-flex flex-wrap align-items-center gap-2 mt-1 pb-1 text-muted small">
                <span class="d-flex align-items-center gap-25">
                    {!! $icon('cart') !!} {{ __('admin.orders_n', ['count' => $totals['orders']]) }}
                </span>
                <span class="d-flex align-items-center gap-25">
                    {!! $icon('money') !!} {{ money($totals['revenue']) }}
                </span>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ bulk --}}
    @if ($selected)
        <div class="card border-primary">
            <div class="card-body py-1 d-flex flex-wrap align-items-center gap-1">
                <span class="fw-bolder">{{ __('admin.n_selected', ['count' => count($selected)]) }}</span>

                <button type="button" class="btn btn-sm btn-primary" wire:click="bulkAdvance">
                    {!! $icon('arrow', 'me-25') !!}{{ __('admin.advance') }}
                </button>

                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                        wire:click="$set('selected', [])">
                    {!! $icon('x') !!}
                </button>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ list --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th style="width:120px">{{ __('admin.order_number') }}</th>
                        <th>{{ __('admin.customer') }}</th>
                        <th style="width:150px">{{ __('admin.delivery') }}</th>
                        <th class="text-center" style="width:80px">{{ __('admin.items') }}</th>
                        <th class="text-end" style="width:120px">{{ __('admin.total') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.payment') }}</th>
                        <th class="text-center" style="width:120px">{{ __('admin.status') }}</th>
                        <th class="text-end" style="width:110px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="o-{{ $order->id }}" @class(['table-active' => in_array($order->id, $selected, true)])>
                            <td>
                                <input type="checkbox" class="form-check-input"
                                       value="{{ $order->id }}" wire:model.live="selected">
                            </td>

                            <td>
                                <a class="fw-bolder text-body" href="{{ route('admin.orders.show', $order) }}">
                                    #{{ $order->number }}
                                </a>
                                <small class="d-block text-muted">{{ $order->created_at->format('d.m.Y H:i') }}</small>
                            </td>

                            <td>
                                <span class="d-block">{{ $order->name }}</span>
                                <small class="text-muted d-flex align-items-center gap-25">
                                    {!! $icon('phone') !!}{{ $order->phone }}
                                </small>
                            </td>

                            <td class="text-muted">
                                {{ $order->city ?: '—' }}
                                @if ($order->address)
                                    <small class="d-block text-truncate" style="max-width:150px">{{ $order->address }}</small>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge bg-light-secondary">{{ $order->items->sum('qty') }}</span>
                            </td>

                            <td class="text-end fw-bolder">{{ money($order->total) }}</td>

                            <td class="text-center">
                                @if ($order->is_paid)
                                    <span class="badge badge-light-success">{{ __('admin.paid') }}</span>
                                @else
                                    <span class="badge badge-light-warning">{{ __('admin.unpaid') }}</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                                    {{ $statuses[$order->status] ?? $order->status }}
                                </span>
                            </td>

                            <td class="text-end text-nowrap">
                                @if (in_array($order->status, \App\Livewire\Admin\Orders\Index::FLOW, true)
                                    && $order->status !== 'completed')
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                            wire:click="advance({{ $order->id }})"
                                            title="{{ __('admin.advance') }}">
                                        {!! $icon('arrow') !!}
                                    </button>
                                @endif

                                <a class="btn btn-sm btn-icon btn-outline-secondary"
                                   href="{{ route('admin.orders.show', $order) }}"
                                   title="{{ __('admin.open') }}">
                                    {!! $icon('eye') !!}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
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
    </div>
</div>
