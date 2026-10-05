@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'eye'    => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'mail'   => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    <div class="card">
        <div class="card-body pb-1">
            <div class="row g-1">
                <div class="col-lg-5 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text">{!! $icon('search') !!}</span>
                        <input type="text" class="form-control"
                               placeholder="{{ __('admin.users_search') }}"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select class="form-select" wire:model.live="filter">
                        <option value="">{{ __('admin.all') }} ({{ $counts['total'] }})</option>
                        <option value="customers">{{ __('admin.customers') }}</option>
                        <option value="admins">{{ __('admin.admins') }} ({{ $counts['admins'] }})</option>
                        <option value="subscribed">{{ __('admin.subscribed') }} ({{ $counts['subscribed'] }})</option>
                    </select>
                </div>

                <div class="col-lg-4 col-md-6">
                    <select class="form-select" wire:model.live="sort">
                        <option value="new">{{ __('admin.sort_new') }}</option>
                        <option value="spent">{{ __('admin.sort_spent') }}</option>
                        <option value="orders">{{ __('admin.sort_orders') }}</option>
                        <option value="name">{{ __('admin.sort_name') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('admin.customer') }}</th>
                        <th style="width:200px">{{ __('admin.contacts') }}</th>
                        <th class="text-center" style="width:100px">{{ __('admin.orders') }}</th>
                        <th class="text-end" style="width:130px">{{ __('admin.spent') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.registered') }}</th>
                        <th class="text-end" style="width:120px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="u-{{ $user->id }}">
                            <td>
                                <a class="fw-bolder text-body" href="{{ route('admin.users.show', $user) }}">
                                    {{ $user->name }}
                                </a>

                                @if ($user->is_admin)
                                    <span class="badge badge-light-primary ms-25">{{ __('admin.admin') }}</span>
                                @endif

                                @if ($user->accepts_marketing)
                                    <span class="text-muted ms-25" title="{{ __('admin.subscribed') }}">{!! $icon('mail') !!}</span>
                                @endif
                            </td>

                            <td class="text-muted">
                                <small class="d-block">{{ $user->email }}</small>
                                @if ($user->phone)
                                    <small><a href="tel:{{ $user->phone }}">{{ $user->phone }}</a></small>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge bg-light-secondary">{{ $user->orders_count }}</span>
                            </td>

                            <td class="text-end fw-bolder">{{ money($user->spent) }}</td>

                            <td class="text-center text-muted">
                                <small>{{ $user->created_at?->format('d.m.Y') }}</small>
                            </td>

                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-icon btn-outline-secondary"
                                   href="{{ route('admin.users.show', $user) }}"
                                   title="{{ __('admin.open') }}">
                                    {!! $icon('eye') !!}
                                </a>

                                <button type="button"
                                        @class(['btn btn-sm btn-icon', 'btn-primary' => $user->is_admin, 'btn-outline-secondary' => ! $user->is_admin])
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
    </div>
</div>
