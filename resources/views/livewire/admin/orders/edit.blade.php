@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'back'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'plus'  => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'minus' => '<line x1="5" y1="12" x2="19" y2="12"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'warn'  => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'sync'  => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
        ];

        return '<svg class="ic '.$class.'" width="15" height="15" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
        <div class="d-flex align-items-center gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                {!! $icon('back', 'me-25') !!}#{{ $order->number }}
            </a>
            <h4 class="mb-0">{{ __('admin.order_edit') }}</h4>
        </div>
    </div>

    @unless ($this->editable())
        <div class="alert alert-secondary">
            <div class="alert-body d-flex align-items-center gap-50">
                {!! $icon('warn') !!}{{ __('admin.order_closed_for_edit') }}
            </div>
        </div>
    @endunless

    @if ($order->is_paid)
        <div class="alert alert-warning">
            <div class="alert-body d-flex align-items-start gap-50">
                {!! $icon('warn') !!}
                <span>{{ __('admin.order_paid_edit_warning', ['amount' => money($order->total)]) }}</span>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ __('admin.order_step_items') }}</h5>
                </div>
                <div class="card-body">
                    <label class="form-label" for="findProduct">{{ __('admin.order_find_product') }}</label>
                    <input type="search" id="findProduct" class="form-control"
                           wire:model.live.debounce.400ms="productSearch"
                           placeholder="{{ __('admin.order_find_product') }}">

                    @if ($found->isNotEmpty())
                        <div class="list-group mt-1">
                            @foreach ($found as $product)
                                <button type="button"
                                        class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:key="found-{{ $product->id }}"
                                        wire:click="add({{ $product->id }})">
                                    <span>
                                        <b>{{ trim(($product->brand?->name ?? '').' '.$product->name) }}</b>
                                        <small class="text-muted ms-50">{{ $product->sku }}</small>
                                    </span>
                                    <span class="text-nowrap">
                                        {{ money($product->sellingPrice()) }}
                                        @if ($product->stock > 0)
                                            <span class="badge badge-light-success ms-50">{{ __('admin.order_in_stock', ['n' => $product->stock]) }}</span>
                                        @else
                                            <span class="badge badge-light-danger ms-50">{{ __('admin.order_no_stock') }}</span>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @error('qty') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('admin.name') }}</th>
                                <th class="text-end" style="width:130px">{{ __('admin.price') }}</th>
                                <th class="text-center" style="width:140px">{{ __('admin.qty') }}</th>
                                <th class="text-end" style="width:120px">{{ __('admin.total') }}</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $line)
                                <tr wire:key="line-{{ $line['id'] }}">
                                    <td>
                                        <span class="fw-bolder d-block">{{ $line['name'] }}</span>
                                        <small class="text-muted">{{ $line['sku'] }}</small>
                                    </td>

                                    <td class="text-end">
                                        {{ money($line['price']) }}

                                        @if ($line['agreed'] && (float) $line['today'] !== (float) $line['price'])
                                            <small class="d-block text-muted">
                                                {{ __('admin.order_price_today', ['amount' => money($line['today'])]) }}
                                            </small>
                                        @endif
                                    </td>

                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-secondary"
                                                    wire:click="decrement({{ $line['id'] }})">{!! $icon('minus') !!}</button>
                                            <span class="btn btn-outline-secondary disabled">{{ $line['qty'] }}</span>
                                            <button type="button" class="btn btn-outline-secondary"
                                                    wire:click="increment({{ $line['id'] }})">{!! $icon('plus') !!}</button>
                                        </div>
                                    </td>

                                    <td class="text-end fw-bolder">{{ money($line['sum']) }}</td>

                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                                wire:click="remove({{ $line['id'] }})">{!! $icon('trash') !!}</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">{{ __('admin.order_needs_an_item') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-muted">{{ __('checkout.subtotal') }}</td>
                                <td class="text-end">{{ money($subtotal) }}</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end text-muted">
                                    {{ __('checkout.shipping') }}
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-50"
                                            wire:click="recalculateShipping"
                                            title="{{ __('admin.order_recalculate_shipping') }}">{!! $icon('sync') !!}</button>
                                </td>
                                <td class="text-end">{{ $shipping > 0 ? money($shipping) : __('checkout.free') }}</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end fw-bolder">{{ __('admin.total') }}</td>
                                <td class="text-end fw-bolder fs-5">{{ money($total) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.customer') }}</h5>
                </div>
                <div class="card-body">
                    <label class="form-label" for="orderName">{{ __('checkout.name') }}</label>
                    <input type="text" id="orderName" class="form-control @error('name') is-invalid @enderror"
                           wire:model="name">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    <label class="form-label mt-1" for="orderPhone">{{ __('checkout.phone') }}</label>
                    <input type="text" id="orderPhone" class="form-control @error('phone') is-invalid @enderror"
                           wire:model="phone">
                    @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    <label class="form-label mt-1" for="orderEmail">{{ __('checkout.email') }}</label>
                    <input type="email" id="orderEmail" class="form-control @error('email') is-invalid @enderror"
                           wire:model="email">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.delivery') }}</h5>
                </div>
                <div class="card-body">
                    <select class="form-select" wire:model.live="delivery">
                        <option value="courier">{{ __('checkout.courier') }}</option>
                        <option value="pickup">{{ __('checkout.pickup') }}</option>
                    </select>

                    @if ($delivery === 'courier')
                        <label class="form-label mt-1" for="orderCity">{{ __('checkout.city') }}</label>
                        <select id="orderCity" class="form-select @error('cityId') is-invalid @enderror"
                                wire:model.live="cityId">
                            <option value="">—</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city->id }}">{{ $city->name }}</option>
                            @endforeach
                        </select>
                        @error('cityId') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        <label class="form-label mt-1" for="orderAddress">{{ __('checkout.address') }}</label>
                        <input type="text" id="orderAddress" class="form-control" wire:model="address">
                    @endif

                    <label class="form-label mt-1" for="orderShipping">{{ __('checkout.shipping') }}</label>
                    <input type="number" step="0.01" min="0" id="orderShipping"
                           class="form-control @error('shippingOverride') is-invalid @enderror"
                           placeholder="{{ __('admin.order_shipping_auto') }}"
                           wire:model.live.debounce.600ms="shippingOverride">
                    @error('shippingOverride') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    <label class="form-label mt-1" for="orderComment">{{ __('checkout.comment') }}</label>
                    <textarea id="orderComment" rows="2" class="form-control" wire:model="comment"></textarea>
                </div>
            </div>

            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('checkout.payment') }}</h5>
                </div>
                <div class="card-body">
                    <select class="form-select @error('payment') is-invalid @enderror" wire:model.live="payment">
                        @foreach ($methods as $method)
                            <option value="{{ $method->code }}" @disabled(! ($fits[$method->code] ?? true))>
                                {{ $method->name }}
                                @unless ($fits[$method->code] ?? true) — {{ __('admin.order_method_unavailable') }} @endunless
                            </option>
                        @endforeach
                    </select>
                    @error('payment') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="card">
                <div class="card-body d-flex gap-1">
                    <button type="button" class="btn btn-primary flex-grow-1"
                            wire:click="save" wire:loading.attr="disabled"
                            @disabled(! $this->editable())>
                        {{ __('admin.save') }}
                    </button>

                    <a class="btn btn-outline-secondary" href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                        {{ __('admin.cancel') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
