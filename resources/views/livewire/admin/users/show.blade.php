@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'back'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'pin'   => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'cart'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'money' => '<circle cx="12" cy="12" r="10"/><path d="M15 9.5a3 3 0 0 0-3-1.5c-1.7 0-3 1-3 2.2 0 2.8 6 1.4 6 4.1 0 1.2-1.3 2.2-3 2.2a3 3 0 0 1-3-1.5"/>',
            'truck' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'user'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'mail'  => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = [
        'new' => 'danger', 'viewed' => 'warning', 'confirmed' => 'info', 'packed' => 'info',
        'shipped' => 'primary', 'completed' => 'success',
        'cancelled' => 'secondary', 'returned' => 'warning',
    ];

    $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('') ?: '?';

    $cards = [
        ['value' => $stats['orders'], 'label' => __('admin.orders'), 'icon' => 'cart', 'tone' => 'primary'],
        ['value' => $stats['active'], 'label' => __('account.orders_active'), 'icon' => 'truck', 'tone' => 'info'],
        ['value' => money($stats['spent']), 'label' => __('admin.spent'), 'icon' => 'money', 'tone' => 'success'],
        ['value' => $stats['last'] ? \Carbon\Carbon::parse($stats['last'])->format('d.m.Y') : '—',
         'label' => __('admin.last_order'), 'icon' => 'clock', 'tone' => 'warning'],
    ];
    $roleTone = [
        'admin'    => 'info',
        'operator' => 'warning',
    ];

    $roleLabel = [
        'admin'    => __('admin.admin'),
        'operator' => __('admin.operator'),
        'customer' => __('admin.customer_role'),
    ];
@endphp

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
        <div class="d-flex align-items-center gap-1">
            <a class="btn btn-sm btn-icon btn-outline-secondary"
               href="{{ route('admin.users') }}" wire:navigate
               title="{{ __('admin.customers') }}">
                {!! $icon('back') !!}
            </a>

            <div class="avatar avatar-lg bg-light-{{ $roleTone[$user->role()] ?? 'primary' }}">
                <span class="avatar-content">{{ $initials }}</span>
            </div>

            <div>
                <h4 class="mb-25 d-flex align-items-center flex-wrap gap-50">
                    {{ $user->name }}

                    @if (isset($roleTone[$user->role()]))
                        <span class="badge rounded-pill badge-light-{{ $roleTone[$user->role()] }}">
                            {{ $roleLabel[$user->role()] }}
                        </span>
                    @endif

                    @if ($user->accepts_marketing)
                        <span class="badge rounded-pill badge-light-success">{{ __('admin.subscribed') }}</span>
                    @endif
                </h4>

                <div class="d-flex flex-wrap align-items-center gap-1 text-muted small">
                    <a class="d-flex align-items-center gap-25 text-muted" href="mailto:{{ $user->email }}">
                        {!! $icon('mail') !!}{{ $user->email }}
                    </a>

                    @if ($user->phone)
                        <a class="d-flex align-items-center gap-25 text-muted" href="tel:{{ $user->phone }}">
                            {!! $icon('phone') !!}{{ $user->phone }}
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <small class="text-muted d-flex align-items-center gap-25">
            {!! $icon('clock') !!}{{ __('admin.registered') }}: {{ $user->created_at?->format('d.m.Y') }}
        </small>
    </div>

    <div class="row">
        @foreach ($cards as $card)
            <div class="col-xl-3 col-md-6 col-12">
                <div class="card">
                    <div class="card-body d-flex align-items-center gap-1">
                        <div class="avatar avatar-lg bg-light-{{ $card['tone'] }}">
                            <span class="avatar-content">{!! $icon($card['icon']) !!}</span>
                        </div>

                        <div>
                            <h4 class="fw-bolder mb-0">{{ $card['value'] }}</h4>
                            <small class="text-muted">{{ $card['label'] }}</small>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-lg-8">

            <x-admin.list-card :title="__('admin.orders')" :count="$orders->count()">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('admin.order_number') }}</th>
                                <th style="width:120px">{{ __('admin.date') }}</th>
                                <th class="text-center" style="width:90px">{{ __('admin.qty') }}</th>
                                <th class="text-center" style="width:140px">{{ __('admin.status') }}</th>
                                <th class="text-end" style="width:130px">{{ __('admin.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($orders as $order)
                                <tr wire:key="o-{{ $order->id }}">
                                    <td>
                                        <a class="fw-bolder" href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                                            #{{ $order->number }}
                                        </a>
                                    </td>
                                    <td class="text-muted"><small>{{ $order->created_at->format('d.m.Y') }}</small></td>
                                    <td class="text-center">{{ $order->items->sum('qty') }}</td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                                            {{ __('order.status.'.$order->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end fw-bolder">{{ money($order->total) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <span class="d-block mb-25">{!! $icon('cart') !!}</span>
                                        {{ __('admin.nothing_here') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-admin.list-card>

            @if ($callbacks->isNotEmpty())
                <x-admin.list-card :title="__('admin.callbacks')" :count="$callbacks->count()">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:160px">{{ __('admin.date') }}</th>
                                    <th>{{ __('admin.comment') }}</th>
                                    <th class="text-end" style="width:140px">{{ __('admin.status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($callbacks as $callback)
                                    <tr wire:key="cb-{{ $callback->id }}">
                                        <td class="text-muted">
                                            <small>{{ $callback->created_at->format('d.m.Y H:i') }}</small>
                                        </td>
                                        <td>{{ $callback->comment ?: '—' }}</td>
                                        <td class="text-end">
                                            <span class="badge rounded-pill badge-light-secondary">
                                                {{ __('admin.cb_'.$callback->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.list-card>
            @endif
        </div>

        <div class="col-lg-4">

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.user_profile') }}</h4>
                </div>

                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label" for="u-name">{{ __('admin.name') }}</label>
                        <input type="text" id="u-name" class="form-control @error('name') is-invalid @enderror" wire:model="name">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="u-email">{{ __('checkout.email') }}</label>
                        <input type="email" id="u-email" class="form-control @error('email') is-invalid @enderror" wire:model="email">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="u-phone">{{ __('checkout.phone') }}</label>
                        <input type="tel" id="u-phone" class="form-control @error('phone') is-invalid @enderror" wire:model="phone">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input type="checkbox" class="form-check-input" id="mk" wire:model="accepts_marketing">
                        <label class="form-check-label" for="mk">{{ __('admin.subscribed') }}</label>
                    </div>

                    <button type="button" class="btn btn-primary w-100" wire:click="save" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">{{ __('admin.save') }}</span>
                        <span wire:loading wire:target="save">{{ __('admin.saving') }}</span>
                    </button>
                </div>
            </div>

            @if ($addresses->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">{{ __('account.addresses') }}</h4>
                    </div>

                    <ul class="list-group list-group-flush">
                        @foreach ($addresses as $address)
                            <li class="list-group-item d-flex gap-1" wire:key="a-{{ $address->id }}">
                                <span class="text-muted mt-25">{!! $icon('pin') !!}</span>

                                <span>
                                    <span class="fw-bolder d-block">{{ $address->label ?: $address->city?->name }}</span>
                                    <small class="text-muted">{{ $address->oneLine() }}</small>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @unless ($user->isStaff())
                <div class="card border-danger">
                    <div class="card-body">
                        <h5 class="mb-50 text-danger">{{ __('admin.close_account') }}</h5>
                        <p class="text-muted small mb-1">{{ __('admin.close_account_hint') }}</p>

                        <button type="button" class="btn btn-outline-danger w-100"
                                wire:click="anonymise"
                                wire:confirm="{{ __('admin.close_account_confirm') }}">
                            {{ __('admin.close_account') }}
                        </button>
                    </div>
                </div>
            @endunless
        </div>
    </div>
</div>
