@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'back'   => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'mail'   => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'pin'    => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'money'  => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'check'  => '<polyline points="20 6 9 17 4 12"/>',
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
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.orders') }}">
                {!! $icon('back', 'me-25') !!}{{ __('admin.orders') }}
            </a>

            <h4 class="mb-0">#{{ $order->number }}</h4>

            <span class="badge badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                {{ $statuses[$order->status] ?? $order->status }}
            </span>

            @if ($order->is_paid)
                <span class="badge badge-light-success">{{ __('admin.paid') }}</span>
            @endif
        </div>

        <small class="text-muted d-flex align-items-center gap-25">
            {!! $icon('clock') !!}{{ $order->created_at->format('d.m.Y H:i') }}
        </small>
    </div>

    <div class="row">
        {{-- ------------------------------------------------------------ items --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('admin.name') }}</th>
                                <th class="text-center" style="width:90px">{{ __('admin.qty') }}</th>
                                <th class="text-end" style="width:120px">{{ __('admin.price') }}</th>
                                <th class="text-end" style="width:120px">{{ __('admin.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr wire:key="i-{{ $item->id }}">
                                    <td>
                                        <span class="fw-bolder d-block">{{ $item->name }}</span>
                                        <small class="text-muted">
                                            {{ $item->sku }}
                                            @if ($item->is_preorder)
                                                · <span class="text-info">{{ __('admin.preorder') }}</span>
                                                @if ($item->release_date)
                                                    {{ $item->release_date->format('d.m.Y') }}
                                                @endif
                                            @endif
                                        </small>
                                    </td>
                                    <td class="text-center">{{ $item->qty }}</td>
                                    <td class="text-end">{{ money($item->price) }}</td>
                                    <td class="text-end fw-bolder">{{ money($item->price * $item->qty) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-muted">{{ __('checkout.subtotal') }}</td>
                                <td class="text-end">{{ money($order->subtotal) }}</td>
                            </tr>
                            @if ($order->discount)
                                <tr>
                                    <td colspan="3" class="text-end text-muted">{{ __('checkout.discount') }}</td>
                                    <td class="text-end text-success">−{{ money($order->discount) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td colspan="3" class="text-end text-muted">{{ __('checkout.shipping') }}</td>
                                <td class="text-end">{{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end fw-bolder">{{ __('admin.total') }}</td>
                                <td class="text-end fw-bolder fs-5">{{ money($order->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- ------------------------------------------------------------ history --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.history') }}</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-1">
                        @forelse ($events as $event)
                            <li class="d-flex gap-1 pb-1 mb-1 border-bottom" wire:key="e-{{ $event->id }}">
                                <span class="text-muted">{!! $icon($event->type === 'status' ? 'check' : 'user') !!}</span>
                                <span class="flex-grow-1">
                                    @if ($event->type === 'status')
                                        <span class="d-block">
                                            {{ $statuses[$event->from] ?? $event->from }}
                                            →
                                            <b>{{ $statuses[$event->to] ?? $event->to }}</b>
                                        </span>
                                    @endif

                                    @if ($event->note)
                                        <span class="d-block">{{ $event->note }}</span>
                                    @endif

                                    <small class="text-muted">
                                        {{ $event->created_at->format('d.m.Y H:i') }}
                                        @if ($event->user) · {{ $event->user->name }} @endif
                                    </small>
                                </span>
                            </li>
                        @empty
                            <li class="text-muted">{{ __('admin.nothing_here') }}</li>
                        @endforelse
                    </ul>

                    <div class="d-flex align-items-start gap-1">
                        <textarea class="form-control" rows="2" placeholder="{{ __('admin.add_note') }}"
                                  wire:model="note"></textarea>
                        <button type="button" class="btn btn-outline-primary text-nowrap" wire:click="addNote">
                            {{ __('admin.add') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ side --}}
        <div class="col-lg-4">
            {{-- status --}}
            <div class="card">
                <div class="card-body">
                    <label class="form-label">{{ __('admin.status') }}</label>
                    <select class="form-select mb-1" wire:model.live="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    @if (in_array($status, ['cancelled', 'returned'], true) && $status !== $order->status)
                        {{-- goods only return to stock when someone confirms they did --}}
                        <div class="form-check form-switch mb-1">
                            <input type="checkbox" class="form-check-input" id="restock" wire:model="restock">
                            <label class="form-check-label" for="restock">{{ __('admin.restock') }}</label>
                        </div>
                    @endif

                    <button type="button" class="btn btn-primary w-100"
                            wire:click="changeStatus" @disabled($status === $order->status)>
                        {{ __('admin.save') }}
                    </button>

                    @unless ($order->is_paid)
                        <button type="button" class="btn btn-outline-success w-100 mt-1"
                                data-confirm="{{ __('admin.mark_paid_confirm') }}"
                                data-confirm-action="markPaid">
                            {!! $icon('money', 'me-25') !!}{{ __('admin.mark_paid') }}
                        </button>
                    @endunless
                </div>
            </div>

            {{-- customer --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.customer') }}</h5>
                </div>
                <div class="card-body">
                    <p class="fw-bolder mb-25">{{ $order->name }}</p>

                    <p class="mb-25 d-flex align-items-center gap-50">
                        {!! $icon('phone') !!}
                        <a href="tel:{{ $order->phone }}">{{ $order->phone }}</a>
                    </p>

                    @if ($order->email)
                        <p class="mb-25 d-flex align-items-center gap-50">
                            {!! $icon('mail') !!}
                            <a href="mailto:{{ $order->email }}">{{ $order->email }}</a>
                        </p>
                    @endif

                    @if ($order->user)
                        <small class="text-muted d-block mt-50">
                            {{ __('admin.registered_customer') }}
                        </small>
                    @endif
                </div>
            </div>

            {{-- delivery --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.delivery') }}</h5>
                </div>
                <div class="card-body">
                    <p class="mb-25 d-flex align-items-start gap-50">
                        {!! $icon('pin') !!}
                        <span>
                            <b>{{ $order->city ?: '—' }}</b>
                            @if ($order->address)
                                <span class="d-block">{{ $order->address }}</span>
                            @endif
                        </span>
                    </p>

                    @if ($order->comment)
                        <div class="alert alert-warning mt-1 mb-0">
                            <div class="alert-body">{{ $order->comment }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
