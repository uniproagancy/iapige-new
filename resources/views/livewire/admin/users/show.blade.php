@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'back'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'pin'   => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = [
        'new' => 'danger', 'confirmed' => 'info', 'packed' => 'info',
        'shipped' => 'primary', 'completed' => 'success',
        'cancelled' => 'secondary', 'returned' => 'warning',
    ];
@endphp

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
        <div class="d-flex align-items-center gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.users') }}">
                {!! $icon('back', 'me-25') !!}{{ __('admin.customers') }}
            </a>

            <h4 class="mb-0">{{ $user->name }}</h4>

            @if ($user->is_admin)
                <span class="badge badge-light-primary">{{ __('admin.admin') }}</span>
            @endif
        </div>

        <small class="text-muted">
            {{ __('admin.registered') }}: {{ $user->created_at?->format('d.m.Y') }}
        </small>
    </div>

    {{-- the three numbers that place a caller at a glance --}}
    <div class="row mb-2">
        <div class="col-md-3 col-6">
            <div class="card mb-1"><div class="card-body py-1">
                <h4 class="mb-0">{{ $stats['orders'] }}</h4>
                <small class="text-muted">{{ __('admin.orders') }}</small>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card mb-1"><div class="card-body py-1">
                <h4 class="mb-0">{{ $stats['active'] }}</h4>
                <small class="text-muted">{{ __('account.orders_active') }}</small>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card mb-1"><div class="card-body py-1">
                <h4 class="mb-0">{{ money($stats['spent']) }}</h4>
                <small class="text-muted">{{ __('admin.spent') }}</small>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card mb-1"><div class="card-body py-1">
                <h4 class="mb-0">{{ $stats['last'] ? \Carbon\Carbon::parse($stats['last'])->format('d.m.Y') : '—' }}</h4>
                <small class="text-muted">{{ __('admin.last_order') }}</small>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- orders --}}
            <div class="card">
                <div class="card-header pb-0"><h5 class="mb-0">{{ __('admin.orders') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <tbody>
                            @forelse ($orders as $order)
                                <tr wire:key="o-{{ $order->id }}">
                                    <td>
                                        <a class="fw-bolder" href="{{ route('admin.orders.show', $order) }}">
                                            #{{ $order->number }}
                                        </a>
                                    </td>
                                    <td class="text-muted">{{ $order->created_at->format('d.m.Y') }}</td>
                                    <td class="text-center">{{ $order->items->sum('qty') }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                                            {{ __('order.status.'.$order->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end fw-bolder">{{ money($order->total) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted py-2">{{ __('admin.nothing_here') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- callbacks: matched by phone too, since a guest leaves no user id --}}
            @if ($callbacks->isNotEmpty())
                <div class="card">
                    <div class="card-header pb-0"><h5 class="mb-0">{{ __('admin.callbacks') }}</h5></div>
                    <div class="card-body">
                        @foreach ($callbacks as $callback)
                            <div class="d-flex justify-content-between gap-1 py-50 border-bottom" wire:key="cb-{{ $callback->id }}">
                                <span>
                                    <small class="text-muted">{{ $callback->created_at->format('d.m.Y H:i') }}</small>
                                    @if ($callback->comment)
                                        <span class="d-block">{{ $callback->comment }}</span>
                                    @endif
                                </span>
                                <span class="badge badge-light-secondary align-self-start">
                                    {{ __('admin.cb_'.$callback->status) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            {{-- editable details, last: the history above is what gets read --}}
            <div class="card">
                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label">{{ __('admin.name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model="name">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label">{{ __('checkout.email') }}</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" wire:model="email">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label">{{ __('checkout.phone') }}</label>
                        <input type="tel" class="form-control" wire:model="phone">
                    </div>

                    <div class="form-check form-switch mb-1">
                        <input type="checkbox" class="form-check-input" id="mk" wire:model="accepts_marketing">
                        <label class="form-check-label" for="mk">{{ __('admin.subscribed') }}</label>
                    </div>

                    <button type="button" class="btn btn-primary w-100" wire:click="save">{{ __('admin.save') }}</button>
                </div>
            </div>

            {{-- addresses --}}
            @if ($addresses->isNotEmpty())
                <div class="card">
                    <div class="card-header pb-0"><h5 class="mb-0">{{ __('account.addresses') }}</h5></div>
                    <div class="card-body">
                        @foreach ($addresses as $address)
                            <p class="mb-1 d-flex gap-50" wire:key="a-{{ $address->id }}">
                                {!! $icon('pin') !!}
                                <span>
                                    <b>{{ $address->label ?: $address->city?->name }}</b>
                                    <span class="d-block small">{{ $address->oneLine() }}</span>
                                </span>
                            </p>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- closing an account keeps its orders; they are accounting records --}}
            @unless ($user->is_admin)
                <div class="card border-danger">
                    <div class="card-body">
                        <h5 class="mb-50">{{ __('admin.close_account') }}</h5>
                        <p class="text-muted small mb-1">{{ __('admin.close_account_hint') }}</p>

                        <button type="button" class="btn btn-outline-danger w-100"
                                data-confirm="{{ __('admin.close_account_confirm') }}"
                                data-confirm-action="anonymise">
                            {{ __('admin.close_account') }}
                        </button>
                    </div>
                </div>
            @endunless
        </div>
    </div>
</div>
