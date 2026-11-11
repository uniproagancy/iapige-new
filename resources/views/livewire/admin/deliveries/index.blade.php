<div class="delivery-board" wire:poll.10s x-data x-on:keydown.escape.window="$wire.closeModal()">
    <style>
        .delivery-board{--delivery-orange:#ff7900;--delivery-border:#40485e;--delivery-muted:#a6adc0;color:#d0d2d6}
        .delivery-board .db-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap}
        .delivery-board h2,.delivery-board h3,.delivery-board h4,.delivery-board h5{color:#f0f2f8}
        .delivery-board .db-heading h2{font-size:25px;font-weight:650;margin-bottom:7px}
        .delivery-board .db-muted{color:var(--delivery-muted);font-size:12px}
        .delivery-board .db-toolbar{border:1px solid var(--delivery-border);border-radius:14px;padding:16px;background:#283046;margin-bottom:14px}
        .delivery-board .db-toolbar label{font-size:12px;color:var(--delivery-muted);margin-bottom:6px}
        .delivery-board .db-search-row{display:grid;grid-template-columns:minmax(220px,1fr) minmax(200px,270px) auto;gap:16px;align-items:start}
        .delivery-board .db-date{display:flex;align-items:stretch;gap:6px}
        .delivery-board .db-date .form-control{min-width:0}
        .delivery-board .form-control,.delivery-board .form-select{min-height:42px}
        .delivery-board .db-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}
        .delivery-board .db-tab{border:1px solid var(--delivery-border);border-radius:9px;background:transparent;color:#c9cee0;padding:9px 13px;display:inline-flex;gap:9px;align-items:center;font-size:13px}
        .delivery-board .db-tab.is-selected{background:#ff790019;color:#ffad65;border-color:var(--delivery-orange)}
        .delivery-board .db-tab span{border-radius:6px;min-width:22px;padding:1px 6px;background:#414b65;color:#fff;font-size:11px}
        .delivery-board .db-meta-bar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0 16px;font-size:12px;color:var(--delivery-muted)}
        .delivery-board .db-live{display:inline-flex;align-items:center;gap:7px}.delivery-board .db-live::before{content:"";width:7px;height:7px;border-radius:50%;background:#28c76f}
        .delivery-board .db-primary{background:var(--delivery-orange);border-color:var(--delivery-orange);color:#fff}
        .delivery-board .db-primary:hover{background:#e66d00;border-color:#e66d00;color:#fff}
        .delivery-board .db-supplier-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:26px 0 12px;padding-left:12px;border-left:4px solid var(--delivery-orange)}
        .delivery-board .db-supplier-heading h3{font-size:18px;margin:0;overflow-wrap:anywhere}
        .delivery-board .db-order{border:1px solid var(--delivery-border);border-left:3px solid #606982;border-radius:14px;background:#283046;margin-bottom:16px;overflow:hidden;box-shadow:0 4px 16px #11172512}
        .delivery-board .db-order.is-active{border-left-color:#ff9f43}
        .delivery-board .db-order.is-picked-up{border-color:#4d9dff66;border-left-color:#4d9dff;background:#24364a}
        .delivery-board .db-order.is-delivered{border-color:#28c76f66;border-left-color:#28c76f;background:#223a36}
        .delivery-board .db-order.is-cancelled{border-color:#ea545566;border-left-color:#ea5455;background:#3f2d37}
        .delivery-board .db-order.is-held{border-color:#ff9f4388}
        .delivery-board .db-order-top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;background:#151d311f;border-bottom:1px solid #ffffff0d;flex-wrap:wrap}
        .delivery-board .db-order-ref{display:flex;align-items:center;gap:12px}
        .delivery-board .db-ordinal{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:9px;background:#ff790022;color:#ffad65;font-size:17px;font-weight:700;flex-shrink:0}
        .delivery-board .db-reference{color:#f0f2f8;font-weight:650;font-size:14px;overflow-wrap:anywhere}
        .delivery-board .db-status{border-radius:7px;padding:6px 10px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
        .delivery-board .db-status-active{background:#ff9f4322;color:#ffbc7c}
        .delivery-board .db-status-picked_up{background:#4d9dff22;color:#8dc3ff}
        .delivery-board .db-status-stack{display:flex;flex-direction:column;align-items:flex-end;gap:5px;text-align:right}.delivery-board .db-status-stack small{font-size:11px}
        .delivery-board .db-status-delivered{background:#28c76f22;color:#6ee6a1}
        .delivery-board .db-status-cancelled{background:#ea545522;color:#ff9293}
        .delivery-board .db-status-held{background:#ff9f4322;color:#ffbc7c}
        .delivery-board .db-order-body{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(270px,1fr)}
        .delivery-board .db-products{padding:18px;border-right:1px solid #ffffff0d}
        .delivery-board .db-product+.db-product{border-top:1px solid #ffffff12;padding-top:16px;margin-top:16px}
        .delivery-board .db-product-name{font-size:16px;font-weight:600;color:#f0f2f8;margin:0 0 8px;line-height:1.55;overflow-wrap:anywhere}
        .delivery-board .db-item-tags{display:flex;gap:6px 14px;flex-wrap:wrap;color:var(--delivery-muted);font-size:12px}
        .delivery-board .db-item-tags strong{color:#e3e6f0;font-weight:500;overflow-wrap:anywhere}
        .delivery-board .db-product-money{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:12px 0;font-size:13px}.delivery-board .db-product-money>strong{font-size:16px;color:#e8ebf5}
        .delivery-board .db-pickup{display:grid;grid-template-columns:minmax(110px,.8fr) minmax(0,1.2fr);gap:14px;border:1px solid #ffffff0a;border-radius:10px;padding:11px 12px;background:#151d3038;font-size:13px}
        .delivery-board .db-pickup div>span,.delivery-board .db-customer div>span{display:block;color:var(--delivery-muted);font-size:11px;margin-bottom:4px}
        .delivery-board .db-pickup strong{font-weight:550;color:#e3e6f0;overflow-wrap:anywhere;white-space:pre-line}.delivery-board .db-pickup small{font-size:11px;line-height:1.5}
        .delivery-board .db-customer{padding:18px;display:grid;gap:14px;align-content:start;font-size:14px}
        .delivery-board .db-contact{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .delivery-board .db-customer-name{font-size:16px;font-weight:600;overflow-wrap:anywhere}
        .delivery-board .db-customer a{color:#ffb778;font-size:15px;font-weight:550;display:inline-block}.delivery-board .db-phone{padding:8px 10px;border:1px solid #ff790033;border-radius:8px;background:#ff790009;white-space:nowrap}
        .delivery-board .db-address{line-height:1.6;overflow-wrap:anywhere;white-space:pre-line}
        .delivery-board .db-assignees{display:grid;grid-template-columns:1fr 1fr;gap:14px;border-top:1px solid #ffffff0d;padding-top:12px;font-size:13px;overflow-wrap:anywhere}
        .delivery-board .db-settlement{margin:0 18px 16px;padding:14px 16px;border:1px solid #ffffff12;border-radius:12px;background:#151d3145;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px 22px;align-items:center}
        .delivery-board .db-payment{display:flex;flex-direction:column;align-items:flex-start;gap:6px;min-width:0}
        .delivery-board .db-payment-value{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.delivery-board .db-payment-value>strong{font-size:14px;font-weight:550;color:#e8ebf5;overflow-wrap:anywhere}.delivery-board .db-payment .badge{font-size:11px}
        .delivery-board .db-finance-label{display:block;color:var(--delivery-muted);font-size:11px;line-height:1.5}
        .delivery-board .db-total{border-top:1px solid #ffffff12;padding-top:13px;display:flex;justify-content:space-between;gap:14px}
        .delivery-board .db-total strong{font-size:22px;color:#f0f2f8;white-space:nowrap}
        .delivery-board .db-settlement .db-total{border:0;padding:0;display:block;text-align:right}.delivery-board .db-settlement .db-total strong{display:block;font-size:26px;line-height:1.3;margin-top:4px}
        .delivery-board .db-breakdown{grid-column:1/-1;display:flex;justify-content:space-between;gap:8px 16px;flex-wrap:wrap;border-top:1px solid #ffffff0d;padding-top:10px;color:var(--delivery-muted);font-size:12px}.delivery-board .db-breakdown strong{font-weight:500;color:#dfe4ef}
        .delivery-board .db-note{padding:9px 11px;border-left:2px solid #ff790055;border-radius:0 8px 8px 0;background:#161d3125;font-size:13px;line-height:1.55;white-space:pre-line;overflow-wrap:anywhere}
        .delivery-board .db-comments{padding:0 18px 15px}
        .delivery-board .db-comments p{margin:4px 0;white-space:pre-line;overflow-wrap:anywhere;font-size:12px}
        .delivery-board .db-order-footer{padding:11px 18px;border-top:1px solid #ffffff0d;background:#151d3112;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.delivery-board .db-order-footer .btn{font-size:12px;padding:8px 11px}
        .delivery-board .db-actions{display:flex;gap:7px;flex-wrap:wrap}
        .delivery-board .db-empty{padding:65px 20px;text-align:center;background:#283046;border:1px dashed var(--delivery-border);border-radius:14px}
        .delivery-board .db-empty h3{font-size:20px;margin-bottom:10px}
        .delivery-board .db-modal{z-index:1060;background:#101522b8;overflow:auto}
        .delivery-board .db-modal .modal-dialog{margin:30px auto;max-width:740px;padding:0 12px}
        .delivery-board .db-modal .modal-content{background:#283046;border:1px solid var(--delivery-border);border-radius:15px;box-shadow:0 20px 80px #0005;color:#d0d2d6}
        .delivery-board .db-modal .modal-header,.delivery-board .db-modal .modal-footer{border-color:var(--delivery-border)}
        .delivery-board .db-modal .modal-title{font-size:19px}
        .delivery-board .db-modal .db-close{background:transparent;border:0;font-size:26px;color:#c9cee0;padding:0 5px;line-height:1}
        .delivery-board .db-modal label{font-size:12px;color:var(--delivery-muted);margin-bottom:6px}
        .delivery-board .db-edit-item{padding:15px;border:1px solid var(--delivery-border);border-radius:10px;margin-top:16px}
        .delivery-board .db-history-item{border-left:2px solid #ff790066;padding:0 0 22px 18px;margin-left:5px}
        .delivery-board .db-history-item:last-child{padding-bottom:0}
        .delivery-board .db-history-item p{white-space:pre-line;overflow-wrap:anywhere;margin:8px 0 0;font-size:13px}
        .delivery-board .db-confirm-item{padding:12px 0;border-bottom:1px solid var(--delivery-border)}
        .delivery-board .db-confirm-item:last-child{border-bottom:0}
        .delivery-board .db-warning{background:#ff9f4317;border:1px solid #ff9f4344;color:#ffca98;padding:12px;border-radius:9px;font-size:12px}
        .delivery-board .db-days{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .delivery-board .db-day{display:flex;align-items:center;gap:10px;border:1px solid var(--delivery-border);border-radius:9px;padding:14px;margin:0!important;cursor:pointer}
        .delivery-board .db-day input{width:18px;height:18px;accent-color:#ff7900}
        @media(max-width:767px){.delivery-board .db-search-row{grid-template-columns:1fr}.delivery-board .db-toolbar{padding:15px}.delivery-board .db-order-body{grid-template-columns:1fr}.delivery-board .db-products{border-right:0;border-bottom:1px solid #ffffff0d}.delivery-board .db-supplier-heading{align-items:flex-start}.delivery-board .db-supplier-heading .db-muted{white-space:nowrap}.delivery-board .db-order-top,.delivery-board .db-products,.delivery-board .db-customer{padding:14px}.delivery-board .db-pickup{grid-template-columns:1fr 1fr;gap:12px}.delivery-board .db-tabs{gap:6px}.delivery-board .db-tab{padding:9px 10px;font-size:12px}.delivery-board .db-settlement{margin:0 14px 14px;padding:13px;grid-template-columns:1fr;gap:12px}.delivery-board .db-settlement .db-total{display:flex;justify-content:space-between;align-items:center;text-align:left;gap:12px}.delivery-board .db-settlement .db-total strong{margin:0;font-size:25px}.delivery-board .db-settlement .db-total .db-finance-label{max-width:55%}.delivery-board .db-breakdown{gap:10px}.delivery-board .db-order-footer{padding:12px 14px}.delivery-board .db-order-footer .db-actions{width:100%}.delivery-board .db-order-footer .btn-success{width:100%;padding:13px;font-size:14px}.delivery-board .db-modal .modal-dialog{margin:12px auto}.delivery-board .db-total strong{font-size:20px}.delivery-board .db-heading h2{font-size:23px}.delivery-board .db-assignees{font-size:13px}.delivery-board .db-contact{gap:10px}.delivery-board .db-comments{padding:0 14px 14px}}
        @media(max-width:380px){.delivery-board .db-pickup{grid-template-columns:1fr}.delivery-board .db-assignees{grid-template-columns:1fr;gap:10px}}
        @media(max-width:767px){.delivery-board .db-order-footer>.db-primary{width:100%;padding:13px;font-size:14px}}
    </style>

    @if($isPreview)
        <div class="db-warning mb-2" role="status">
            <strong class="d-block mb-1">{{ __('delivery.courier_preview_title') }}</strong>
            {{ __('delivery.courier_preview_help') }}
            <a class="d-block mt-1" href="{{ route('admin.deliveries') }}">← {{ __('delivery.back_to_deliveries') }}</a>
        </div>
    @endif

    <div class="db-heading">
        <div>
            @unless($isStaff)<h2>{{ __('delivery.title') }}</h2>@endunless
            <p class="db-muted mb-0">{{ $isStaff ? __('delivery.subtitle') : __('delivery.courier_subtitle') }}</p>
        </div>
        <div class="d-flex gap-1 flex-wrap">
            @if($isOwner)
                <a href="{{ route('admin.deliveries.courier-preview') }}" class="btn btn-outline-secondary btn-sm">{{ __('delivery.courier_view') }}</a>
                <a href="{{ route('admin.operator-sales') }}" class="btn btn-outline-secondary btn-sm">{{ __('operator_sales.title') }}</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="openSchedule">{{ __('delivery.schedule') }}</button>
            @endif
            @if($isStaff)
                <a href="{{ route('admin.orders.create') }}" class="btn db-primary btn-sm">+ {{ __('delivery.new_order') }}</a>
            @endif
        </div>
    </div>

    <div class="db-toolbar">
        <div class="db-search-row">
            <div>
                <label for="delivery-search">{{ __('delivery.search') }}</label>
                <input id="delivery-search" type="search" class="form-control" maxlength="100" wire:model.live.debounce.350ms="search" placeholder="{{ __('delivery.search_placeholder') }}">
                @if(trim($search) !== '' && $allDates)
                    <small class="db-muted d-block mt-1">{{ __('delivery.search_help') }}</small>
                @endif
            </div>
            <div>
                <label for="delivery-date">{{ __('delivery.date') }}</label>
                <div class="db-date">
                    <button type="button" class="btn btn-outline-secondary px-1" wire:click="shiftDate(-1)" aria-label="{{ __('delivery.previous_date') }}">‹</button>
                    <input id="delivery-date" type="date" class="form-control" wire:model.live="date">
                    <button type="button" class="btn btn-outline-secondary px-1" wire:click="shiftDate(1)" aria-label="{{ __('delivery.next_date') }}">›</button>
                </div>
                @error('date') <small class="text-danger">{{ $message }}</small> @enderror
            </div>
            <div class="pt-2">
                <label class="d-flex align-items-center gap-1 mt-1">
                    <input type="checkbox" class="form-check-input m-0" wire:model.live="allDates">
                    {{ __('delivery.all_dates') }}
                </label>
            </div>
        </div>
        <div class="db-tabs" role="group" aria-label="{{ __('delivery.title') }}">
            @foreach(['all', 'active', 'delivered', 'cancelled'] as $tab)
                <button type="button" class="db-tab {{ $status === $tab ? 'is-selected' : '' }}" wire:click="setStatus('{{ $tab }}')" aria-pressed="{{ $status === $tab ? 'true' : 'false' }}">
                    {{ __('delivery.'.$tab) }}
                    <span>{{ (int) ($tab === 'all' ? $counts->sum() : ($counts[$tab] ?? 0)) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="db-meta-bar">
        <span>{{ __('delivery.delivery_days') }}: {{ collect($weekdays)->map(fn($day) => __('delivery.weekday_'.$day))->join(' · ') }}</span>
        <span class="db-live">{{ __('delivery.live') }}</span>
    </div>

    @if($deliveries->isNotEmpty())
        <div class="db-meta-bar">
            <strong>{{ __('delivery.orders_count', ['count' => $deliveries->total()]) }}</strong>
            <span>{{ __('delivery.page_total') }}: <strong>{{ money($deliveries->sum('total')) }}</strong></span>
        </div>
    @endif

    @forelse($groups as $supplierName => $entries)
        <section wire:key="supplier-{{ md5($supplierName) }}">
            <div class="db-supplier-heading">
                <h3>{{ $supplierName }}</h3>
                <span class="db-muted">{{ __('delivery.group_orders', ['count' => count($entries)]) }}</span>
            </div>
            @foreach($entries as $entry)
                @php
                    $delivery = $entry['delivery'];
                    $multipleSuppliers = $delivery->items->groupBy(fn($item) => trim((string) $item->supplier_name))->count() > 1;
                    $isHeld = $delivery->status === 'active' && !$delivery->is_eligible;
                    $isPickedUp = $delivery->status === 'active' && $delivery->isPickedUp();
                    $visibleStatus = $isHeld ? 'held' : ($isPickedUp ? 'picked_up' : $delivery->status);
                    $orderComments = $comments->get($delivery->order_id, collect());
                @endphp
                <article class="db-order is-{{ $delivery->status }} {{ $isHeld ? 'is-held' : ($isPickedUp ? 'is-picked-up' : '') }}" wire:key="delivery-{{ md5($supplierName) }}-{{ $delivery->id }}">
                    <div class="db-order-top">
                        <div class="db-order-ref">
                            <span class="db-ordinal">{{ $entry['ordinal'] }}</span>
                            <div>
                                @if($isStaff && $delivery->order && !$delivery->order->trashed())
                                    <a class="db-reference" href="{{ route('admin.orders.show', $delivery->order_id) }}">{{ $delivery->order->number }}</a>
                                @else
                                    <span class="db-reference">{{ $delivery->order?->number ?? '#'.$delivery->order_id }}</span>
                                @endif
                                <div class="db-muted mt-1">{{ $delivery->delivery_date->format('d.m.Y') }} · {{ __('delivery.weekday_'.$delivery->delivery_date->dayOfWeek) }}</div>
                            </div>
                        </div>
                        <div class="db-status-stack">
                            <span class="db-status db-status-{{ $visibleStatus }}">{{ $delivery->status === 'delivered' ? '✓' : ($delivery->status === 'cancelled' ? '×' : '·') }} {{ __('delivery.'.$visibleStatus) }}</span>
                            @if($isPickedUp)<small class="db-muted">{{ __('delivery.picked_up_at') }}: {{ $delivery->picked_up_at->timezone('Asia/Tbilisi')->format('d.m H:i') }}</small>@endif
                        </div>
                    </div>
                    @if($isHeld)
                        <div class="db-warning m-1 mb-0">{{ __('delivery.held_help') }}</div>
                    @endif
                    <div class="db-order-body">
                        <div class="db-products">
                            @foreach($entry['items'] as $item)
                                <div class="db-product">
                                    <h4 class="db-product-name">{{ $item->name }}</h4>
                                    <div class="db-item-tags">
                                        <span>{{ __('delivery.sku') }}: <strong>{{ $item->sku ?: '—' }}</strong></span>
                                        <span>{{ __('delivery.supplier_sku') }}: <strong>{{ $item->supplier_sku ?: '—' }}</strong></span>
                                    </div>
                                    <div class="db-product-money">
                                        <span>{{ __('delivery.quantity') }}: <strong>{{ $item->qty }}</strong> × {{ money($item->price) }}</span>
                                        <strong>{{ money($item->sum) }}</strong>
                                    </div>
                                    <div class="db-pickup">
                                        <div>
                                            <span>{{ __('delivery.supplier') }}</span>
                                            <strong>{{ trim((string) $item->supplier_name) ?: __('delivery.unassigned_supplier') }}</strong>
                                            @if(trim((string) $item->warehouse) !== '')
                                                <small class="db-muted d-block mt-1">{{ __('delivery.warehouse') }}: {{ trim((string) $item->warehouse) }}</small>
                                            @endif
                                        </div>
                                        <div><span>{{ __('delivery.pickup_address') }}</span><strong>{{ $item->pickup_address ?: __('delivery.unassigned') }}</strong></div>
                                    </div>
                                    @if(trim((string) $item->supplier_name) === '')
                                        <small class="text-warning d-block mt-1">{{ __('delivery.missing_supplier_help') }}</small>
                                    @endif
                                </div>
                            @endforeach
                            @if($multipleSuppliers)
                                <p class="db-muted mt-2 mb-0" title="{{ __('delivery.multi_supplier_help') }}">{{ __('delivery.multi_supplier') }}</p>
                            @endif
                        </div>
                        <div class="db-customer">
                            <div class="db-contact">
                                <div><span>{{ __('delivery.customer') }}</span><div class="db-customer-name">{{ $delivery->name }}</div></div>
                                <a class="db-phone" href="tel:{{ preg_replace('/[^0-9+]/', '', $delivery->phone) }}">{{ $delivery->phone }}</a>
                            </div>
                            <div>
                                <span>{{ __('delivery.address') }}</span>
                                <div class="db-address">{{ collect([$delivery->city, $delivery->address])->filter()->join(', ') }}</div>
                            </div>
                            <div class="db-assignees">
                                <div><span>{{ __('delivery.courier') }}</span>{{ $delivery->courier?->name ?? __('delivery.unassigned_courier') }}</div>
                                <div><span>{{ __('delivery.operator') }}</span>{{ $delivery->order?->completedBy?->user?->name ?? __('delivery.unassigned') }}</div>
                            </div>
                            @if($delivery->customer_comment)
                                <div class="db-note"><span class="db-muted d-block mb-1">{{ __('delivery.customer_comment') }}</span>{{ $delivery->customer_comment }}</div>
                            @endif
                            @if($delivery->notes)
                                <div class="db-note"><span class="db-muted d-block mb-1">{{ __('delivery.notes') }}</span>{{ $delivery->notes }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="db-settlement">
                        <div class="db-payment">
                            <span class="db-finance-label">{{ __('delivery.payment') }}</span>
                            <div class="db-payment-value">
                                <strong>{{ $delivery->payment === 'cash' ? __('delivery.cash_to_courier') : ($methods->get($delivery->payment)?->name ?? $delivery->payment) }}</strong>
                                @if($delivery->payment === 'cash')
                                    <span aria-hidden="true">·</span><span class="badge rounded-pill {{ $delivery->hasCashReceipt() ? 'bg-light-success' : 'bg-light-warning' }}">{{ $delivery->hasCashReceipt() ? __('delivery.cash_received') : ($delivery->status === 'cancelled' ? __('delivery.cancelled') : __('delivery.cash_pending')) }}</span>
                                @else
                                    <span class="badge rounded-pill {{ $delivery->is_paid ? 'bg-light-success' : 'bg-light-danger' }}">{{ $delivery->is_paid ? ($delivery->payment === 'transfer' ? __('delivery.transfer_received') : __('delivery.paid')) : __('delivery.unpaid') }}</span>
                                @endif
                            </div>
                            @if($delivery->payment === 'transfer')
                                <small class="db-transfer-bank db-muted">{{ __('delivery.payment_bank') }}: {{ trim((string) $delivery->payment_bank) ?: __('delivery.payment_bank_missing') }}</small>
                            @endif
                        </div>
                        <div class="db-total">
                            <span class="db-finance-label">{{ __('delivery.order_total') }}</span>
                            <strong>{{ $delivery->payment === 'cash' ? money($delivery->total, 2) : money($delivery->total) }}</strong>
                        </div>
                        <div class="db-breakdown">
                            <span>{{ __('delivery.subtotal') }} <strong>{{ $delivery->payment === 'cash' ? money($delivery->subtotal, 2) : money($delivery->subtotal) }}</strong> + {{ __('delivery.shipping') }} <strong>{{ $delivery->payment === 'cash' ? money($delivery->shipping, 2) : money($delivery->shipping) }}</strong></span>
                            @if(!$isStaff || $delivery->requiresCashReceipt())
                                <span class="db-courier-due {{ ($delivery->payment === 'cash' ? $delivery->hasCashReceipt() : $delivery->is_paid) ? 'text-success' : 'text-warning' }}">{{ $delivery->payment === 'cash' ? __('delivery.cash_due') : __('delivery.courier_due') }}: <strong>{{ $delivery->amountDue() !== null ? money($delivery->amountDue(), 2) : '—' }}</strong></span>
                            @endif
                        </div>
                    </div>
                    @if($orderComments->isNotEmpty())
                        <div class="db-comments">
                            <span class="db-muted">{{ __('delivery.comments') }}</span>
                            @foreach($orderComments->take(2)->reverse() as $comment)
                                <p>{{ $comment->note }}</p>
                                <small class="db-muted">{{ $comment->user?->name ?? __('delivery.system') }} · {{ $comment->created_at->timezone('Asia/Tbilisi')->format('d.m H:i') }}</small>
                            @endforeach
                        </div>
                    @endif
                    <div class="db-order-footer">
                        <div class="db-actions">
                            @unless($isPreview)
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="openHistory({{ $delivery->id }})">{{ __('delivery.history') }}</button>
                            @endunless
                            @if($isStaff)
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="openComment({{ $delivery->id }})">{{ __('delivery.comment') }}</button>
                                @if($delivery->isEditable())
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="openEdit({{ $delivery->id }})">{{ __('delivery.edit') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" wire:click="openPostpone({{ $delivery->id }})">{{ __('delivery.postpone') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="openCancel({{ $delivery->id }})">{{ __('delivery.cancel_delivery') }}</button>
                                @endif
                            @endif
                        </div>
                        @if(!$isPreview && $delivery->canPickUp())
                            <button type="button" class="btn db-primary" wire:click="openPickedUp({{ $delivery->id }})">{{ __('delivery.mark_picked_up') }}</button>
                        @elseif(!$isPreview && $delivery->canDeliver() && $delivery->isPickedUp())
                            <button type="button" class="btn btn-success" wire:click="openDelivered({{ $delivery->id }})">✓ {{ $delivery->requiresCashReceipt() ? __('delivery.cash_delivery') : __('delivery.mark_delivered') }}</button>
                        @elseif(!$isPreview && $delivery->status === 'delivered' && $delivery->requiresCashReceipt())
                            <button type="button" class="btn db-primary" wire:click="openCashReceipt({{ $delivery->id }})">{{ __('delivery.receive_cash') }}</button>
                        @endif
                        @if($delivery->status === 'delivered' && $delivery->delivered_at)
                            <span class="db-muted">{{ __('delivery.delivered_at') }}: {{ $delivery->delivered_at->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @empty
        <div class="db-empty">
            <h3>{{ __('delivery.empty') }}</h3>
            <p class="db-muted">{{ __('delivery.empty_help') }}</p>
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="$refresh">{{ __('delivery.refresh') }}</button>
        </div>
    @endforelse

    <div class="mt-2">{{ $deliveries->links() }}</div>

    @if($modal)
        @php
            $modalTitles = ['edit' => 'edit_title', 'postpone' => 'postpone_title', 'comment' => 'comment', 'cancel' => 'cancel_title', 'picked-up' => 'confirm_picked_up', 'delivered' => 'confirm_delivered', 'cash-delivered' => 'confirm_cash_delivered', 'cash-received' => 'confirm_cash_received', 'history' => 'history', 'schedule' => 'schedule'];
        @endphp
        <div class="modal d-block db-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="delivery-modal-title" wire:key="delivery-modal-{{ $modal }}-{{ $activeId ?? 'settings' }}">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h4 id="delivery-modal-title" class="modal-title">{{ __('delivery.'.($modalTitles[$modal] ?? 'title')) }}</h4>
                            @if($selectedDelivery)
                                <small class="db-muted">{{ $selectedDelivery->order?->number ?? '#'.$selectedDelivery->order_id }} · {{ $selectedDelivery->name }}</small>
                            @endif
                        </div>
                        <button type="button" class="db-close" wire:click="closeModal" aria-label="{{ __('delivery.close') }}">×</button>
                    </div>
                    <form wire:submit="{{ $modal === 'edit' ? 'saveEdit' : ($modal === 'schedule' ? 'saveSchedule' : 'saveAction') }}">
                        <div class="modal-body">
                            @if($errors->any())
                                <div class="alert alert-danger" role="alert">
                                    <strong>{{ __('delivery.validation') }}</strong>
                                    <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                                </div>
                            @endif
                            @if($modal === 'edit' && $isStaff && $selectedDelivery)
                                <p class="db-muted">{{ __('delivery.edit_help') }}</p>
                                <div class="row g-2">
                                    <div class="col-md-6"><label for="delivery-name">{{ __('delivery.name') }}</label><input id="delivery-name" class="form-control" wire:model="form.name" required maxlength="255"></div>
                                    <div class="col-md-6"><label for="delivery-phone">{{ __('delivery.phone') }}</label><input id="delivery-phone" type="tel" class="form-control" wire:model="form.phone" required maxlength="32"></div>
                                    <div class="col-md-4"><label for="delivery-city">{{ __('delivery.city') }}</label><input id="delivery-city" class="form-control" value="{{ $selectedDelivery->city ?: __('delivery.unassigned') }}" readonly aria-describedby="delivery-city-help"></div>
                                    <div class="col-md-8"><label for="delivery-address">{{ __('delivery.address') }}</label><input id="delivery-address" class="form-control" wire:model="form.address" required maxlength="255"></div>
                                    <div id="delivery-city-help" class="col-12 db-muted">{{ __('delivery.city_changes_original') }}</div>
                                    <div class="col-md-6"><label for="edit-delivery-date">{{ __('delivery.date') }}</label><input id="edit-delivery-date" type="date" class="form-control" wire:model="form.delivery_date" required></div>
                                    <div class="col-md-6">
                                        <label for="delivery-courier">{{ __('delivery.courier') }}</label>
                                        <select id="delivery-courier" class="form-select" wire:model="form.courier_id">
                                            <option value="">{{ __('delivery.unassigned_courier') }}</option>
                                            @foreach($couriers as $courier)<option value="{{ $courier->id }}">{{ $courier->name }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div class="col-12"><label for="delivery-notes">{{ __('delivery.notes') }}</label><textarea id="delivery-notes" class="form-control" wire:model="form.notes" rows="2" maxlength="2000"></textarea></div>
                                    @if($selectedDelivery->payment === 'transfer')
                                        <div class="col-12"><label for="delivery-bank">{{ __('delivery.payment_bank') }}</label><input id="delivery-bank" class="form-control" wire:model="form.payment_bank" maxlength="120"><small class="db-muted d-block mt-1">{{ __('delivery.payment_bank_help') }}</small></div>
                                    @endif
                                </div>
                                @foreach($selectedDelivery->items as $item)
                                    <div class="db-edit-item" wire:key="edit-delivery-item-{{ $item->id }}">
                                        <h5 class="db-product-name">{{ $item->name }}</h5>
                                        <p class="db-muted">{{ $item->qty }} × {{ money($item->price) }} · {{ __('delivery.sku') }}: {{ $item->sku }}</p>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label for="supplier-{{ $item->id }}">{{ __('delivery.supplier') }}</label>
                                                <select id="supplier-{{ $item->id }}" class="form-select" wire:model.live="itemForms.{{ $item->id }}.supplier_id">
                                                    <option value="">{{ __('delivery.supplier_manual') }}</option>
                                                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="supplier-name-{{ $item->id }}">{{ __('delivery.supplier_name') }}</label>
                                                <input id="supplier-name-{{ $item->id }}" class="form-control" wire:model="itemForms.{{ $item->id }}.supplier_name" maxlength="191" @disabled(!empty($itemForms[$item->id]['supplier_id']))>
                                            </div>
                                            <div class="col-md-6"><label for="supplier-sku-{{ $item->id }}">{{ __('delivery.supplier_sku') }}</label><input id="supplier-sku-{{ $item->id }}" class="form-control" wire:model="itemForms.{{ $item->id }}.supplier_sku" maxlength="191"></div>
                                            <div class="col-md-6"><label for="warehouse-{{ $item->id }}">{{ __('delivery.warehouse') }}</label><input id="warehouse-{{ $item->id }}" class="form-control" wire:model="itemForms.{{ $item->id }}.warehouse" maxlength="255"></div>
                                            <div class="col-12"><label for="pickup-{{ $item->id }}">{{ __('delivery.pickup_address') }}</label><input id="pickup-{{ $item->id }}" class="form-control" wire:model="itemForms.{{ $item->id }}.pickup_address" maxlength="500"></div>
                                        </div>
                                    </div>
                                @endforeach
                                <div class="db-muted mt-2">{{ __('delivery.financial_help') }} <a href="{{ route('admin.orders.show', $selectedDelivery->order_id) }}">{{ __('delivery.edit_order') }} ↗</a></div>
                            @elseif($modal === 'schedule' && $isOwner)
                                <p class="db-muted">{{ __('delivery.schedule_help') }}</p>
                                <div class="db-days">
                                    @foreach([1, 2, 3, 4, 5, 6, 0] as $day)
                                        <label class="db-day"><input type="checkbox" value="{{ $day }}" wire:model="workingDays"><span>{{ __('delivery.weekday_'.$day) }}</span></label>
                                    @endforeach
                                </div>
                            @elseif($modal === 'history' && $selectedDelivery)
                                @forelse($events as $event)
                                    @php
                                        $eventKey = 'delivery.event_'.substr($event->type, strlen('delivery_'));
                                    @endphp
                                    <div class="db-history-item">
                                        <strong>{{ \Illuminate\Support\Facades\Lang::has($eventKey) ? __($eventKey) : __('delivery.event_changed') }}</strong>
                                        <div class="db-muted mt-1">{{ $event->created_at->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }} · {{ $event->user?->name ?? __('delivery.system') }}</div>
                                        @if(($event->from || $event->to) && $event->type !== 'delivery_assigned')
                                            @php
                                                $eventValues = ['active' => __('delivery.active'), 'delivered' => __('delivery.delivered'), 'cancelled' => __('delivery.cancelled'), 'ready' => __('delivery.active'), 'held' => __('delivery.held'), 'picked_up' => __('delivery.picked_up'), 'paid' => __('delivery.paid'), 'unpaid' => __('delivery.unpaid')];
                                            @endphp
                                            <div class="db-muted mt-1">{{ $eventValues[$event->from] ?? $event->from }} → {{ $eventValues[$event->to] ?? $event->to }}</div>
                                        @endif
                                        @if($event->note)<p>{{ $event->note }}</p>@endif
                                    </div>
                                @empty
                                    <p class="db-muted mb-0">{{ __('delivery.history_empty') }}</p>
                                @endforelse
                                @if($events->count() === 100)<p class="db-muted mt-2 mb-0">{{ __('delivery.history_limit') }}</p>@endif
                            @elseif($modal === 'picked-up' && $selectedDelivery)
                                <div class="db-warning mb-2">{{ __('delivery.confirm_picked_up_help') }}</div>
                                <h5>{{ __('delivery.whole_order') }}</h5>
                                @foreach($selectedDelivery->items as $item)
                                    <div class="db-confirm-item">
                                        <strong class="d-block">{{ $item->name }}</strong>
                                        <small class="db-muted">{{ trim((string) $item->supplier_name) ?: __('delivery.unassigned_supplier') }} · {{ $item->supplier_sku ?: $item->sku }}</small>
                                        <div class="mt-1">{{ __('delivery.quantity') }}: <strong>{{ $item->qty }}</strong></div>
                                        @if(trim((string) $item->warehouse) !== '')<small class="db-muted d-block">{{ __('delivery.warehouse') }}: {{ $item->warehouse }}</small>@endif
                                        @if($item->pickup_address)<small class="db-muted d-block">{{ __('delivery.pickup_address') }}: {{ $item->pickup_address }}</small>@endif
                                    </div>
                                @endforeach
                            @elseif(in_array($modal, ['delivered', 'cash-delivered', 'cash-received'], true) && $selectedDelivery)
                                @if($modal !== 'cash-received')<div class="db-warning mb-2">{{ __('delivery.confirm_delivered_help') }}</div>@endif
                                @if(in_array($modal, ['cash-delivered', 'cash-received'], true))
                                    <div class="alert alert-warning" role="alert">{{ __($modal === 'cash-received' ? 'delivery.cash_receipt_only_help' : 'delivery.cash_receipt_help', ['amount' => money(($expectedCashAmount ?? 0) / 100, 2)]) }}</div>
                                @endif
                                <h5>{{ __('delivery.whole_order') }}</h5>
                                @foreach($selectedDelivery->items as $item)
                                    <div class="db-confirm-item">
                                        <strong class="d-block">{{ $item->name }}</strong>
                                        <small class="db-muted">{{ $item->supplier_name ?: __('delivery.unassigned_supplier') }} · {{ $item->supplier_sku ?: $item->sku }}</small>
                                        <div class="d-flex justify-content-between mt-1"><span>{{ $item->qty }} × {{ $modal !== 'delivered' ? money($item->price, 2) : money($item->price) }}</span><strong>{{ $modal !== 'delivered' ? money($item->sum, 2) : money($item->sum) }}</strong></div>
                                    </div>
                                @endforeach
                                <div class="db-total mt-2"><span>{{ __('delivery.order_total') }}</span><strong>{{ $modal !== 'delivered' ? money(($expectedCashAmount ?? 0) / 100, 2) : money($selectedDelivery->total) }}</strong></div>
                                <div class="d-flex justify-content-between mt-2 {{ $modal !== 'delivered' ? 'text-warning' : 'text-success' }}"><span>{{ __('delivery.courier_due') }}</span><strong>{{ $modal !== 'delivered' ? money(($expectedCashAmount ?? 0) / 100, 2) : money(0) }}</strong></div>
                                @if($modal === 'delivered')<p class="db-muted mt-2 mb-0">{{ __('delivery.confirm_delivered_payment') }}</p>@endif
                            @elseif(in_array($modal, ['postpone', 'comment', 'cancel'], true) && $isStaff)
                                @if($modal === 'postpone')
                                    <p class="db-muted">{{ __('delivery.postpone_help') }}</p>
                                    <label for="postpone-date">{{ __('delivery.new_date') }}</label>
                                    <input id="postpone-date" type="date" class="form-control mb-2" wire:model="newDate" required>
                                @elseif($modal === 'cancel')
                                    <div class="db-warning mb-2">{{ __('delivery.cancel_help') }}</div>
                                @endif
                                <label for="delivery-reason">{{ $modal === 'comment' ? __('delivery.comments') : __('delivery.reason') }}</label>
                                <textarea id="delivery-reason" class="form-control" rows="4" wire:model="reason" required minlength="2" maxlength="2000" @if($modal === 'comment') placeholder="{{ __('delivery.comment_placeholder') }}" @endif></textarea>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" wire:click="closeModal">{{ __('delivery.close') }}</button>
                            @if($modal !== 'history')
                                <button type="submit" class="btn {{ $modal === 'cancel' ? 'btn-danger' : (in_array($modal, ['delivered', 'cash-delivered', 'cash-received'], true) ? 'btn-success' : 'db-primary') }}" wire:loading.attr="disabled" wire:target="saveEdit,saveAction,saveSchedule">
                                    <span wire:loading.remove wire:target="saveEdit,saveAction,saveSchedule">{{ $modal === 'picked-up' ? __('delivery.confirm_all_picked_up') : ($modal === 'cash-received' ? __('delivery.cash_receipt_only_confirm', ['amount' => money(($expectedCashAmount ?? 0) / 100, 2)]) : ($modal === 'cash-delivered' ? __('delivery.cash_received_confirm', ['amount' => money(($expectedCashAmount ?? 0) / 100, 2)]) : ($modal === 'delivered' ? __('delivery.mark_delivered') : ($modal === 'cancel' ? __('delivery.cancel_delivery') : __('delivery.save'))))) }}</span>
                                    <span wire:loading wire:target="saveEdit,saveAction,saveSchedule">{{ __('delivery.saving') }}</span>
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
