@php
    $max = max(1, collect($week)->max('revenue'));
@endphp

<div>
    {{-- today, against yesterday: a number alone says nothing --}}
    <div class="row">
        <div class="col-lg-3 col-6">
            <div class="card"><div class="card-body">
                <h3 class="mb-0">{{ $today['orders'] }}</h3>
                <small class="text-muted">{{ __('admin.orders_today') }}</small>
                @if ($today['orders_yesterday'])
                    <small class="d-block text-muted">
                        {{ __('admin.yesterday') }}: {{ $today['orders_yesterday'] }}
                    </small>
                @endif
            </div></div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card"><div class="card-body">
                <h3 class="mb-0">{{ money($today['revenue']) }}</h3>
                <small class="text-muted">{{ __('admin.revenue_today') }}</small>
            </div></div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card"><div class="card-body">
                <h3 class="mb-0 {{ $waiting['new_orders'] ? 'text-danger' : '' }}">{{ $waiting['new_orders'] }}</h3>
                <small class="text-muted">{{ __('admin.awaiting_call') }}</small>
            </div></div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card"><div class="card-body">
                <h3 class="mb-0 {{ $waiting['callbacks'] ? 'text-danger' : '' }}">{{ $waiting['callbacks'] }}</h3>
                <small class="text-muted">{{ __('admin.callbacks') }}</small>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- a week's shape, drawn with divs: a chart library for seven bars is a lot --}}
            <div class="card">
                <div class="card-header pb-0"><h5 class="mb-0">{{ __('admin.week_revenue') }}</h5></div>
                <div class="card-body">
                    <div class="d-flex align-items-end gap-1" style="height:140px">
                        @foreach ($week as $day)
                            <div class="flex-fill text-center">
                                <div style="height:{{ max(4, round($day['revenue'] / $max * 110)) }}px;background:var(--bs-primary,#7367F0);border-radius:6px 6px 0 0"
                                     title="{{ money($day['revenue']) }}"></div>
                                <small class="text-muted d-block mt-50">{{ $day['label'] }}</small>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- recent orders --}}
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('admin.recent_orders') }}</h5>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders') }}">
                        {{ __('admin.all') }}
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <tbody>
                            @forelse ($recent as $order)
                                <tr wire:key="o-{{ $order->id }}">
                                    <td>
                                        <a class="fw-bolder" href="{{ route('admin.orders.show', $order) }}">
                                            #{{ $order->number }}
                                        </a>
                                    </td>
                                    <td class="text-muted">{{ $order->name }}</td>
                                    <td class="text-muted">{{ $order->created_at->diffForHumans() }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-light-secondary">
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
        </div>

        <div class="col-lg-4">
            {{-- what is on somebody's desk --}}
            <div class="card">
                <div class="card-header pb-0"><h5 class="mb-0">{{ __('admin.waiting') }}</h5></div>
                <div class="card-body">
                    @foreach ([
                        ['label' => __('admin.awaiting_call'), 'n' => $waiting['new_orders'], 'url' => route('admin.orders').'?status=new'],
                        ['label' => __('admin.packing'),       'n' => $waiting['packing'],    'url' => route('admin.orders').'?status=confirmed'],
                        ['label' => __('admin.unpaid'),        'n' => $waiting['unpaid'],     'url' => route('admin.orders')],
                        ['label' => __('admin.callbacks'),     'n' => $waiting['callbacks'],  'url' => route('admin.callbacks')],
                    ] as $item)
                        <a class="d-flex justify-content-between align-items-center py-50 text-body"
                           href="{{ $item['url'] }}">
                            <span>{{ $item['label'] }}</span>
                            <span @class(['badge', 'badge-light-danger' => $item['n'] > 0, 'badge-light-secondary' => $item['n'] === 0])>
                                {{ $item['n'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- catalogue faults, each linking to the list that fixes them --}}
            <div class="card">
                <div class="card-header pb-0"><h5 class="mb-0">{{ __('admin.catalogue_health') }}</h5></div>
                <div class="card-body">
                    @foreach ([
                        ['label' => __('admin.status_draft'), 'n' => $problems['drafts'],  'q' => 'status=draft'],
                        ['label' => __('admin.no_category'),  'n' => $problems['nocat'],   'q' => 'issue=nocat'],
                        ['label' => __('admin.no_price'),     'n' => $problems['noprice'], 'q' => 'issue=noprice'],
                        ['label' => __('admin.no_photo'),     'n' => $problems['nophoto'], 'q' => 'issue=nophoto'],
                    ] as $item)
                        @continue (! $item['n'])
                        <a class="d-flex justify-content-between align-items-center py-50 text-body"
                           href="{{ route('admin.products') }}?{{ $item['q'] }}">
                            <span>{{ $item['label'] }}</span>
                            <span class="badge badge-light-warning">{{ $item['n'] }}</span>
                        </a>
                    @endforeach

                    @if (! array_filter($problems))
                        <p class="text-muted mb-0">{{ __('admin.all_clear') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
