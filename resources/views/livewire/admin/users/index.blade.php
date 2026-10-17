@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'eye'    => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'mail'   => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'money'  => '<circle cx="12" cy="12" r="10"/><path d="M15 9.5a3 3 0 0 0-3-1.5c-1.7 0-3 1-3 2.2 0 2.8 6 1.4 6 4.1 0 1.2-1.3 2.2-3 2.2a3 3 0 0 1-3-1.5"/>',
            'x'      => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $initials = fn (?string $name) => collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('') ?: '?';

    $groups = [
        '' => ['label' => __('admin.all'), 'count' => $counts['total'], 'tone' => 'secondary'],
        'customers' => ['label' => __('admin.customers'), 'count' => $counts['customers'], 'tone' => 'primary'],
        'admins' => ['label' => __('admin.admins'), 'count' => $counts['admins'], 'tone' => 'info'],
        'subscribed' => ['label' => __('admin.subscribed'), 'count' => $counts['subscribed'], 'tone' => 'success'],
    ];
@endphp

<div>
    <div class="d-flex flex-wrap gap-1 mb-2">
        @foreach ($groups as $key => $group)
            <button type="button"
                    @class(['btn btn-sm',
                        'btn-'.$group['tone'] => $filter === $key,
                        'btn-outline-'.$group['tone'] => $filter !== $key])
                    wire:click="$set('filter', '{{ $key }}')">
                {{ $group['label'] }}
                <span class="badge bg-white text-dark ms-50">{{ $group['count'] }}</span>
            </button>
        @endforeach
    </div>

    <x-admin.list-card :title="__('admin.customers')" :count="$totals['people']">
        <x-slot:filters>
            <div class="col-lg-4 col-md-6">
                <label class="form-label">{{ __('admin.search') }}</label>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{!! $icon('search') !!}</span>
                    <input type="text" class="form-control"
                           placeholder="{{ __('admin.users_search') }}"
                           wire:model.live.debounce.400ms="search">
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">{{ __('admin.orders') }}</label>
                <select class="form-select" wire:model.live="hasOrders">
                    <option value="">{{ __('admin.users_any_orders') }}</option>
                    <option value="yes">{{ __('admin.users_with_orders') }}</option>
                    <option value="no">{{ __('admin.users_without_orders') }}</option>
                </select>
            </div>

            <div class="col-lg-4 col-md-6">
                <label class="form-label">{{ __('admin.registered') }}</label>
                <div class="d-flex align-items-center gap-1">
                    <input type="date" class="form-control" wire:model.live="from">
                    <span class="text-muted">—</span>
                    <input type="date" class="form-control" wire:model.live="to">
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">{{ __('admin.sort_by') }}</label>
                <select class="form-select" wire:model.live="sort">
                    <option value="new">{{ __('admin.sort_new') }}</option>
                    <option value="spent">{{ __('admin.sort_spent') }}</option>
                    <option value="orders">{{ __('admin.sort_orders') }}</option>
                    <option value="name">{{ __('admin.sort_name') }}</option>
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
                    {!! $icon('user') !!} {{ __('admin.customers_n', ['count' => $totals['people']]) }}
                </span>
                <span class="d-flex align-items-center gap-25">
                    {!! $icon('money') !!} {{ money($totals['spent']) }}
                </span>
            </div>
        </x-slot:filters>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin.customer') }}</th>
                        <th style="width:220px">{{ __('admin.contacts') }}</th>
                        <th class="text-center" style="width:100px">{{ __('admin.orders') }}</th>
                        <th class="text-end" style="width:140px">{{ __('admin.spent') }}</th>
                        <th class="text-center" style="width:120px">{{ __('admin.registered') }}</th>
                        <th class="text-end" style="width:120px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="u-{{ $user->id }}">
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar avatar-sm me-1 {{ $user->is_admin ? 'bg-light-info' : 'bg-light-primary' }}">
                                        <span class="avatar-content">{{ $initials($user->name) }}</span>
                                    </div>

                                    <div class="d-flex flex-column">
                                        <a class="fw-bolder text-body text-truncate"
                                           href="{{ route('admin.users.show', $user) }}" wire:navigate>
                                            {{ $user->name }}
                                        </a>

                                        <div class="d-flex flex-wrap align-items-center gap-25">
                                            @if ($user->is_admin)
                                                <span class="badge rounded-pill badge-light-info">{{ __('admin.admin') }}</span>
                                            @endif

                                            @if ($user->accepts_marketing)
                                                <span class="text-muted" title="{{ __('admin.subscribed') }}">{!! $icon('mail') !!}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <a class="d-block text-truncate small" href="mailto:{{ $user->email }}">{{ $user->email }}</a>

                                @if ($user->phone)
                                    <a class="small text-muted" href="tel:{{ $user->phone }}">{{ $user->phone }}</a>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge rounded-pill badge-light-{{ $user->orders_count ? 'primary' : 'secondary' }}">
                                    {{ $user->orders_count }}
                                </span>
                            </td>

                            <td class="text-end fw-bolder">{{ money($user->spent) }}</td>

                            <td class="text-center text-muted">
                                <small>{{ $user->created_at?->format('d.m.Y') }}</small>
                            </td>

                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-icon btn-outline-secondary"
                                   href="{{ route('admin.users.show', $user) }}"
                                   wire:navigate
                                   title="{{ __('admin.open') }}">
                                    {!! $icon('eye') !!}
                                </a>

                                <button type="button"
                                        @class(['btn btn-sm btn-icon', 'btn-info' => $user->is_admin, 'btn-outline-secondary' => ! $user->is_admin])
                                        wire:click="toggleAdmin({{ $user->id }})"
                                        title="{{ $user->is_admin ? __('admin.revoke_admin') : __('admin.make_admin') }}">
                                    {!! $icon('shield') !!}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('user') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="card-body pt-1">{{ $users->links() }}</div>
        @endif
    </x-admin.list-card>
</div>
