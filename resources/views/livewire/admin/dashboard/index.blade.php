@php
    $max = max(1, collect($week)->max('revenue'));

    $orders = route('admin.orders');

    $cards = [
        [
            'value' => $today['orders'],
            'label' => __('admin.orders_today'),
            'url' => $orders,
            'note' => $today['orders_yesterday']
                ? __('admin.yesterday').': '.$today['orders_yesterday']
                : null,
        ],
        [
            'value' => money($today['revenue']),
            'label' => __('admin.revenue_today'),
            'url' => $orders,
        ],
        [
            'value' => $waiting['new_orders'],
            'label' => __('admin.awaiting_call'),
            'url' => $orders.'?status=new',
            'alarm' => $waiting['new_orders'] > 0,
        ],
        [
            'value' => $waiting['callbacks'],
            'label' => __('admin.callbacks'),
            'url' => route('admin.callbacks'),
            'alarm' => $waiting['callbacks'] > 0,
        ],
    ];
@endphp

<div>

    <div class="row">
        @foreach ($cards as $card)
            <div class="col-lg-3 col-6">
                <a class="card stat" href="{{ $card['url'] }}">
                    <div class="card-body">
                        <span class="stat__value {{ ($card['alarm'] ?? false) ? 'text-danger' : '' }}">
                            {{ $card['value'] }}
                        </span>
                        <span class="stat__label">{{ $card['label'] }}</span>

                        @if ($card['note'] ?? null)
                            <small class="d-block text-muted mt-25">{{ $card['note'] }}</small>
                        @endif
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-lg-8">

            <div class="card">
                <div class="card-header pb-0"><h4 class="card-title mb-0">{{ __('admin.week_revenue') }}</h4></div>
                <div class="card-body">
                    <div class="spark">
                        @foreach ($week as $day)
                            <div class="spark__col">
                                <span class="spark__value">
                                    {{ $day['revenue'] > 0 ? money($day['revenue']) : '—' }}
                                </span>
                                <div class="spark__bar"
                                     style="height:{{ max(3, round($day['revenue'] / $max * 110)) }}px"></div>
                                <span class="spark__day">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">{{ __('admin.recent_orders') }}</h4>
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
                                        <span class="badge rounded-pill badge-light-secondary">
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

            <div class="card">
                <div class="card-header pb-0"><h4 class="card-title mb-0">{{ __('admin.waiting') }}</h4></div>
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

            <div class="card">
                <div class="card-header pb-0"><h4 class="card-title mb-0">{{ __('admin.catalogue_health') }}</h4></div>
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
                            <span class="badge rounded-pill badge-light-warning">{{ $item['n'] }}</span>
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
