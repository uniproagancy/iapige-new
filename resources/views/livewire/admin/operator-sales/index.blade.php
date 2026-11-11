<div class="operator-sales">
    @php
        $amount = static fn($cents) => number_format((int) $cents / 100, 2, '.', ' ').' ₾';
        $operatorName = static fn($name, $id) => trim((string) $name) !== '' ? $name : ($id ? __('operator_sales.former_operator').' #'.$id : __('operator_sales.unassigned'));
        $statusLabels = \App\Models\Order::labels();
        $selectedOperatorListed = $operator === '' || $operator === '0' || $operators->contains(fn($row) => (string) $row->operator_id === $operator);
    @endphp
    <style>
        .operator-sales{--os-border:#40485e;--os-muted:#a6adc0;color:#d0d2d6}
        .operator-sales h3,.operator-sales h4{color:#f0f2f8}
        .operator-sales .os-heading{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;margin-bottom:20px}
        .operator-sales .os-heading p{font-size:13px;color:var(--os-muted);margin:0;line-height:1.6}
        .operator-sales .os-owner{font-size:11px;white-space:nowrap;color:#ffb778;background:#ff79001a;border:1px solid #ff790044;border-radius:7px;padding:7px 10px}
        .operator-sales .os-panel{background:#283046;border:1px solid var(--os-border);border-radius:14px;margin-bottom:20px;overflow:hidden}
        .operator-sales .os-filters{display:grid;grid-template-columns:minmax(160px,1fr) minmax(160px,1fr) minmax(180px,1.2fr) auto;gap:14px;padding:18px;align-items:end}
        .operator-sales label{display:block;font-size:12px;color:var(--os-muted);margin-bottom:7px}
        .operator-sales .form-control,.operator-sales .form-select{min-height:42px}
        .operator-sales .os-filter-actions{display:flex;gap:8px;flex-wrap:wrap}
        .operator-sales .os-note{font-size:12px;line-height:1.7;color:var(--os-muted);padding:0 18px 17px}
        .operator-sales .os-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
        .operator-sales .os-stat{background:#283046;border:1px solid var(--os-border);border-radius:13px;padding:18px}
        .operator-sales .os-stat-label{font-size:12px;color:var(--os-muted);line-height:1.6;min-height:38px;margin-bottom:10px}
        .operator-sales .os-stat strong{display:block;font-size:26px;font-weight:650;color:#f1f3f9;white-space:nowrap}
        .operator-sales .os-stat small{display:block;font-size:11px;color:var(--os-muted);margin-top:10px}
        .operator-sales .os-stat.is-net{border-color:#28c76f66}.operator-sales .os-stat.is-net strong{color:#73e5a6}
        .operator-sales .os-stat.is-cancelled strong{color:#ff9293}
        .operator-sales .os-stat.is-commission{border-color:#ff790066;background:#3c302d}.operator-sales .os-stat.is-commission strong{color:#ffb778}
        .operator-sales .os-panel-title{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px;border-bottom:1px solid var(--os-border);flex-wrap:wrap}
        .operator-sales .os-panel-title h3{font-size:17px;font-weight:600;margin:0}
        .operator-sales .os-panel-title p{font-size:11px;color:var(--os-muted);margin:5px 0 0}
        .operator-sales .os-panel-title>span{font-size:12px;color:var(--os-muted)}
        .operator-sales table{width:100%;border-collapse:collapse;font-size:12px}
        .operator-sales th{background:#30394f;color:#b9c1d4;font-weight:500;white-space:nowrap;font-size:11px;padding:12px 15px}
        .operator-sales td{padding:14px 15px;border-bottom:1px solid #ffffff0d;vertical-align:middle}
        .operator-sales tbody tr:last-child td{border-bottom:0}
        .operator-sales .os-number{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .operator-sales .os-table-total td{background:#30394f;border-top:1px solid var(--os-border);font-weight:650}
        .operator-sales .os-operator-link{border:0;padding:0;background:transparent;color:#ffb778;text-align:left;font-weight:550;font-size:13px;max-width:250px;overflow-wrap:anywhere}
        .operator-sales .os-muted{font-size:11px;color:var(--os-muted);line-height:1.6}
        .operator-sales .os-order-link{font-weight:600;white-space:nowrap;color:#dbe0ed}
        .operator-sales .os-customer{min-width:170px;max-width:250px;overflow-wrap:anywhere}
        .operator-sales .os-customer a{display:block;font-size:11px;margin-top:5px;color:#ffb778}
        .operator-sales .os-cancelled td{background:#ea545512}.operator-sales .os-cancelled .os-order-link{color:#ff9293}
        .operator-sales .os-inactive td{background:#ff9f4309}
        .operator-sales .os-badge{display:inline-block;padding:4px 7px;font-size:10px;border-radius:5px;line-height:1.4;white-space:nowrap}
        .operator-sales .os-badge-success{background:#28c76f1a;color:#7ae8ac}
        .operator-sales .os-badge-danger{background:#ea54551a;color:#ff9293}
        .operator-sales .os-badge-warning{background:#ff9f431a;color:#ffbc7c}
        .operator-sales .os-status{display:flex;gap:5px;align-items:center;flex-wrap:wrap;min-width:135px;max-width:230px}
        .operator-sales .os-pagination{padding:15px 18px;border-top:1px solid var(--os-border)}
        .operator-sales .os-pagination nav{margin:0}.operator-sales .os-pagination .pagination{margin-bottom:0}
        .operator-sales .os-empty{text-align:center;color:var(--os-muted);padding:35px 20px}
        @media(max-width:1100px){.operator-sales .os-filters{grid-template-columns:1fr 1fr}.operator-sales .os-stats{grid-template-columns:1fr 1fr}.operator-sales .os-stat-label{min-height:0}}
        @media(max-width:575px){.operator-sales .os-heading{flex-wrap:wrap}.operator-sales .os-filters{padding:14px;gap:12px}.operator-sales .os-filters>div:nth-child(3),.operator-sales .os-filter-actions{grid-column:1/-1}.operator-sales .os-filter-actions .btn{flex:1}.operator-sales .os-stats{gap:10px}.operator-sales .os-stat{padding:14px 12px}.operator-sales .os-stat strong{font-size:20px}.operator-sales .os-stat-label{font-size:11px}.operator-sales .os-panel-title{padding:15px}.operator-sales th,.operator-sales td{padding:12px}.operator-sales .os-note{padding:0 14px 15px}}
    </style>

    <div class="os-heading">
        <p>{{ __('operator_sales.subtitle') }}</p>
        <span class="os-owner">{{ __('operator_sales.owner_only') }}</span>
    </div>

    <div class="os-panel">
        @if($errors->any())
            <div class="alert alert-warning m-1 mb-0" role="alert">{{ __('operator_sales.validation') }}</div>
        @endif
        <form wire:submit="applyFilters">
            <div class="os-filters">
                <div>
                    <label for="sales-from">{{ __('operator_sales.from') }}</label>
                    <input id="sales-from" type="date" class="form-control" wire:model="from" required>
                    @error('from')<small class="text-danger">{{ __('operator_sales.validation') }}</small>@enderror
                </div>
                <div>
                    <label for="sales-to">{{ __('operator_sales.to') }}</label>
                    <input id="sales-to" type="date" class="form-control" wire:model="to" required>
                    @error('to')<small class="text-danger">{{ __('operator_sales.validation') }}</small>@enderror
                </div>
                <div>
                    <label for="sales-operator">{{ __('operator_sales.operator') }}</label>
                    <select id="sales-operator" class="form-select" wire:model="operator">
                        <option value="">{{ __('operator_sales.all_operators') }}</option>
                        <option value="0">{{ __('operator_sales.unassigned') }}</option>
                        @if(!$selectedOperatorListed)
                            <option value="{{ $operator }}">{{ __('operator_sales.selected_operator', ['id' => $operator]) }}</option>
                        @endif
                        @foreach($operators as $row)
                            @if($row->operator_id !== null)
                                <option value="{{ $row->operator_id }}">{{ $operatorName($row->operator_name, $row->operator_id) }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('operator')<small class="text-danger">{{ __('operator_sales.invalid_operator') }}</small>@enderror
                </div>
                <div class="os-filter-actions">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('operator_sales.apply') }}</button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="resetFilters">{{ __('operator_sales.this_month') }}</button>
                </div>
            </div>
        </form>
        <div class="os-note">{{ __('operator_sales.period_note') }}<br>{{ __('operator_sales.from') }}: <strong>{{ $appliedFrom }}</strong> · {{ __('operator_sales.to') }}: <strong>{{ $appliedTo }}</strong></div>
    </div>

    <div class="os-stats">
        <div class="os-stat">
            <div class="os-stat-label">{{ __('operator_sales.gross') }}</div>
            <strong>{{ $amount($totals['gross_products_cents']) }}</strong>
            <small>{{ __('operator_sales.orders_count', ['count' => $totals['gross_count']]) }}</small>
        </div>
        <div class="os-stat is-cancelled">
            <div class="os-stat-label">{{ __('operator_sales.cancelled') }}</div>
            <strong>{{ $totals['cancelled_products_cents'] > 0 ? '−' : '' }}{{ $amount($totals['cancelled_products_cents']) }}</strong>
            <small>{{ __('operator_sales.cancelled_count', ['count' => $totals['paid_cancelled_count']]) }}</small>
        </div>
        <div class="os-stat is-net">
            <div class="os-stat-label">{{ __('operator_sales.net') }}</div>
            <strong>{{ $amount($totals['net_products_cents']) }}</strong>
            <small>{{ __('operator_sales.orders_count', ['count' => $totals['net_count']]) }}</small>
        </div>
        <div class="os-stat is-commission">
            <div class="os-stat-label">{{ __('operator_sales.commission') }}</div>
            <strong>{{ $amount($totals['commission_cents']) }}</strong>
            <small>{{ __('operator_sales.unpaid_count', ['count' => $totals['unpaid_count']]) }} · {{ __('operator_sales.inactive_count', ['count' => $totals['inactive_count']]) }}</small>
        </div>
    </div>

    <div class="os-panel">
        <div class="os-panel-title">
            <div><h3>{{ __('operator_sales.summary') }}</h3><p>{{ __('operator_sales.summary_help') }}</p></div>
            @if($operator !== '')<button type="button" class="btn btn-sm btn-outline-secondary" wire:click="selectOperator('')">{{ __('operator_sales.all_operators') }}</button>@endif
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                <tr>
                    <th>{{ __('operator_sales.operator') }}</th>
                    <th class="os-number">{{ __('operator_sales.orders') }}</th>
                    <th class="os-number">{{ __('operator_sales.gross_column') }}</th>
                    <th class="os-number">{{ __('operator_sales.cancelled_column') }}</th>
                    <th class="os-number">{{ __('operator_sales.net_column') }}</th>
                    <th class="os-number">{{ __('operator_sales.commission_column') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($summary as $row)
                    <tr wire:key="operator-total-{{ $row->operator_id ?? 'unassigned' }}">
                        <td><button type="button" class="os-operator-link" wire:click="selectOperator('{{ $row->operator_id ?? 0 }}')">{{ $operatorName($row->operator_name, $row->operator_id) }}</button></td>
                        <td class="os-number">{{ $row->orders_count }}</td>
                        <td class="os-number">{{ $amount($row->gross_products_cents) }}</td>
                        <td class="os-number text-danger">{{ $row->cancelled_products_cents > 0 ? '−' : '' }}{{ $amount($row->cancelled_products_cents) }}</td>
                        <td class="os-number">{{ $amount($row->net_products_cents) }}</td>
                        <td class="os-number text-success"><strong>{{ $amount($row->commission_cents) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="os-empty">{{ __('operator_sales.empty_operators') }}</td></tr>
                @endforelse
                </tbody>
                @if($summary->isNotEmpty())
                    <tfoot><tr class="os-table-total">
                        <td>{{ __('operator_sales.total') }}</td>
                        <td class="os-number">{{ $totals['orders_count'] }}</td>
                        <td class="os-number">{{ $amount($totals['gross_products_cents']) }}</td>
                        <td class="os-number text-danger">{{ $totals['cancelled_products_cents'] > 0 ? '−' : '' }}{{ $amount($totals['cancelled_products_cents']) }}</td>
                        <td class="os-number">{{ $amount($totals['net_products_cents']) }}</td>
                        <td class="os-number text-success">{{ $amount($totals['commission_cents']) }}</td>
                    </tr></tfoot>
                @endif
            </table>
        </div>
        <div class="os-note pt-2">{{ __('operator_sales.basis_note') }} {{ __('operator_sales.unassigned_note') }}<br>{{ __('operator_sales.rounding_note') }}</div>
    </div>

    <div class="os-panel">
        <div class="os-panel-title">
            <h3>{{ __('operator_sales.details') }}</h3>
            <span>{{ __('operator_sales.orders_count', ['count' => $orders->total()]) }}</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr>
                    <th>{{ __('operator_sales.order') }}</th>
                    <th>{{ __('operator_sales.completed_at') }}</th>
                    <th>{{ __('operator_sales.customer') }}</th>
                    <th>{{ __('operator_sales.operator') }}</th>
                    <th class="os-number">{{ __('operator_sales.products') }}</th>
                    <th class="os-number">{{ __('operator_sales.commission_basis') }}</th>
                    <th>{{ __('operator_sales.status') }}</th>
                </tr></thead>
                <tbody>
                @forelse($orders as $order)
                    <tr class="{{ $order->is_cancelled ? 'os-cancelled' : ($order->is_inactive ? 'os-inactive' : '') }}" wire:key="sales-order-{{ $order->id }}">
                        <td>
                            @if(!$order->trashed())
                                <a href="{{ route('admin.orders.show', $order->id) }}" class="os-order-link">{{ $order->number }}</a>
                            @else
                                <span class="os-order-link">{{ $order->number }}</span>
                                <div class="os-muted">{{ __('operator_sales.deleted') }}</div>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ \Carbon\CarbonImmutable::parse($order->completed_at, config('app.timezone', 'UTC'))->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }}</td>
                        <td class="os-customer">{{ $order->name }}<a href="tel:{{ preg_replace('/[^0-9+]/', '', $order->phone) }}">{{ $order->phone }}</a></td>
                        <td>{{ $operatorName($order->completed_by_name, $order->completed_by_id) }}</td>
                        <td class="os-number">{{ $amount($order->products_cents) }}</td>
                        <td class="os-number">
                            {{ $amount($order->commissionable_products_cents) }}
                            @if($order->is_cancelled || $order->is_inactive || !$order->is_paid || !$order->completed_by_id)
                                <div class="os-muted">{{ __('operator_sales.no_commission') }}</div>
                            @endif
                        </td>
                        <td><div class="os-status">
                            @if($order->is_cancelled)
                                <span class="os-badge os-badge-danger">{{ __('operator_sales.cancelled_status') }}</span>
                            @elseif($order->is_inactive)
                                <span class="os-badge os-badge-warning">{{ __('operator_sales.inactive_status') }}</span>
                            @else
                                <span class="os-badge os-badge-success">{{ $statusLabels[$order->status] ?? $order->status }}</span>
                            @endif
                            <span class="os-badge {{ $order->is_paid ? 'os-badge-success' : 'os-badge-warning' }}">{{ $order->is_paid ? __('operator_sales.paid') : __('operator_sales.unpaid') }}</span>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="os-empty">{{ __('operator_sales.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())<div class="os-pagination">{{ $orders->links() }}</div>@endif
    </div>
</div>
