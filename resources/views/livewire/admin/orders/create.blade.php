@php
    $labels = [
        'customer' => __('admin.order_step_customer'),
        'items'    => __('admin.order_step_items'),
        'delivery' => __('admin.order_step_delivery'),
        'payment'  => __('admin.order_step_payment'),
    ];

    $steps = array_keys($labels);
    $at    = array_search($step, $steps, true);
@endphp

<div>

    <div class="card mb-2">
        <div class="card-body py-1">
            <div class="d-flex flex-wrap align-items-center gap-1">
                @foreach ($labels as $key => $label)
                    @php $i = array_search($key, $steps, true); @endphp

                    <button type="button"
                            @class(['btn btn-sm d-flex align-items-center gap-50',
                                'btn-primary'           => $i === $at,
                                'btn-outline-success'    => $i < $at,
                                'btn-outline-secondary'  => $i > $at])
                            @disabled($i > $at)
                            wire:click="goTo('{{ $key }}')">
                        <span class="badge rounded-pill bg-white text-dark">{{ $i + 1 }}</span>
                        {{ $label }}
                    </button>

                    @if (! $loop->last)
                        <span class="text-muted">—</span>
                    @endif
                @endforeach

                @if ($callbackId)
                    <span class="badge badge-light-info ms-auto">{{ __('admin.order_from_callback') }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row">

        <div class="col-lg-8">

            @if ($step === 'customer')
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('admin.order_step_customer') }}</h4>
                    </div>
                    <div class="card-body">
                        @if ($userId)
                            <div class="alert alert-success d-flex justify-content-between align-items-center p-1">
                                <span>
                                    <b>{{ __('admin.order_registered') }}</b> — {{ $name }}
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="forgetCustomer">
                                    {{ __('admin.order_detach_customer') }}
                                </button>
                            </div>
                        @else
                            <div class="mb-1">
                                <label class="form-label">{{ __('admin.order_find_customer') }}</label>
                                <input type="search" class="form-control"
                                       wire:model.live.debounce.400ms="customerSearch"
                                       placeholder="{{ __('admin.order_find_customer') }}">

                                @if ($customers->isNotEmpty())
                                    <div class="list-group mt-1">
                                        @foreach ($customers as $customer)
                                            <button type="button" class="list-group-item list-group-item-action"
                                                    wire:key="cust-{{ $customer->id }}"
                                                    wire:click="useCustomer({{ $customer->id }})">
                                                <b>{{ $customer->name }}</b>
                                                <small class="text-muted ms-50">
                                                    {{ collect([$customer->phone, $customer->email])->filter()->implode(' · ') }}
                                                </small>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                <small class="text-muted">{{ __('admin.order_guest_hint') }}</small>
                            </div>
                        @endif

                        <div class="row">
                            <div class="col-md-4 mb-1">
                                <label class="form-label" for="o-name">{{ __('admin.order_name') }} *</label>
                                <input id="o-name" type="text" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 mb-1">
                                <label class="form-label" for="o-phone">{{ __('admin.order_phone') }} *</label>
                                <input id="o-phone" type="text" class="form-control @error('phone') is-invalid @enderror"
                                       wire:model="phone">
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 mb-1">
                                <label class="form-label" for="o-email">{{ __('admin.order_email') }}</label>
                                <input id="o-email" type="email" class="form-control @error('email') is-invalid @enderror"
                                       wire:model="email">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($step === 'items')
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('admin.order_step_items') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="mb-1">
                            <label class="form-label">{{ __('admin.order_find_product') }}</label>
                            <input type="search" class="form-control"
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
                        </div>

                        @error('qty') <div class="alert alert-danger p-1">{{ $message }}</div> @enderror

                        @if ($lines)
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ __('admin.order_step_items') }}</th>
                                            <th class="text-end">{{ __('admin.price') }}</th>
                                            <th class="text-center" style="width:150px">{{ __('admin.qty') }}</th>
                                            <th class="text-end">{{ __('admin.order_total') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lines as $line)
                                            <tr wire:key="line-{{ $line['id'] }}">
                                                <td>
                                                    {{ $line['name'] }}
                                                    <small class="d-block text-muted">{{ $line['sku'] }}</small>
                                                </td>
                                                <td class="text-end">{{ money($line['price']) }}</td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary"
                                                                wire:click="decrement({{ $line['id'] }})">−</button>
                                                        <span class="btn btn-outline-secondary disabled">{{ $line['qty'] }}</span>
                                                        <button type="button" class="btn btn-outline-secondary"
                                                                wire:click="increment({{ $line['id'] }})">+</button>
                                                    </div>
                                                    @if ($line['qty'] > $line['stock'])
                                                        <small class="d-block text-danger">{{ __('admin.order_in_stock', ['n' => $line['stock']]) }}</small>
                                                    @endif
                                                </td>
                                                <td class="text-end fw-bolder">{{ money($line['sum']) }}</td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                                            wire:click="remove({{ $line['id'] }})">×</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted mb-0">{{ __('admin.order_empty') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($step === 'delivery')
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('admin.order_step_delivery') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-1 mb-1">
                            @foreach (['courier' => __('admin.order_delivery_courier'), 'pickup' => __('admin.order_delivery_pickup')] as $key => $label)
                                <button type="button"
                                        @class(['btn btn-sm', 'btn-primary' => $delivery === $key, 'btn-outline-primary' => $delivery !== $key])
                                        wire:click="$set('delivery', '{{ $key }}')">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>

                        @if ($delivery === 'courier')
                            <div class="row">
                                <div class="col-md-5 mb-1">
                                    <label class="form-label" for="o-city">{{ __('admin.order_city') }} *</label>
                                    <select id="o-city" class="form-select @error('cityId') is-invalid @enderror"
                                            wire:model.live="cityId">
                                        <option value="">—</option>
                                        @foreach ($cities as $city)
                                            <option value="{{ $city->id }}">{{ $city->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('cityId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-7 mb-1">
                                    <label class="form-label" for="o-address">{{ __('admin.order_address') }} *</label>
                                    <input id="o-address" type="text"
                                           class="form-control @error('address') is-invalid @enderror"
                                           wire:model="address">
                                    @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="mb-1">
                                <label class="form-label" for="o-ship">{{ __('admin.order_shipping_override') }}</label>
                                <div class="input-group">
                                    <input id="o-ship" type="number" step="0.01" min="0"
                                           class="form-control @error('shippingOverride') is-invalid @enderror"
                                           wire:model.live.debounce.600ms="shippingOverride"
                                           placeholder="{{ __('admin.order_shipping_auto') }}: {{ money($shipping) }}">
                                    <span class="input-group-text">₾</span>
                                    @error('shippingOverride') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <small class="text-muted">{{ __('admin.order_shipping_override_hint') }}</small>
                            </div>
                        @endif

                        <div class="mb-0">
                            <label class="form-label" for="o-comment">{{ __('admin.order_comment') }}</label>
                            <textarea id="o-comment" rows="3"
                                      class="form-control @error('comment') is-invalid @enderror"
                                      wire:model="comment"></textarea>
                            @error('comment') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            @endif

            @if ($step === 'payment')
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('admin.order_step_payment') }}</h4>
                    </div>
                    <div class="card-body">
                        @error('payment') <div class="alert alert-danger p-1">{{ $message }}</div> @enderror

                        <div class="list-group mb-1">
                            @foreach ($methods as $method)
                                @php $available = $fits[$method->code] ?? true; @endphp

                                <label @class(['list-group-item d-flex align-items-center gap-1',
                                               'opacity-50' => ! $available])
                                       wire:key="pay-{{ $method->id }}">
                                    <input type="radio" class="form-check-input mt-0"
                                           value="{{ $method->code }}"
                                           wire:model.live="payment"
                                           @disabled(! $available)>

                                    @if ($method->logoUrl())
                                        <img src="{{ $method->logoUrl() }}" alt="" height="20">
                                    @endif

                                    <span>
                                        <b>{{ $method->name ?: $method->code }}</b>
                                        @if ($method->note)
                                            <small class="d-block text-muted">{{ $method->note }}</small>
                                        @endif
                                        @unless ($available)
                                            <small class="d-block text-danger">{{ __('admin.order_payment_unreachable') }}</small>
                                        @endunless
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="alert alert-warning p-1 mb-0">{{ __('admin.order_not_paid_yet') }}</div>
                    </div>
                </div>
            @endif

            <div class="d-flex justify-content-between mb-2">
                <button type="button" class="btn btn-outline-secondary"
                        @disabled($at === 0) wire:click="back">
                    {{ __('admin.order_back') }}
                </button>

                @if ($step === 'payment')
                    <button type="button" class="btn btn-success" wire:click="place" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="place">{{ __('admin.order_place') }}</span>
                        <span wire:loading wire:target="place">{{ __('admin.order_place') }}…</span>
                    </button>
                @else
                    <button type="button" class="btn btn-primary" wire:click="next">
                        {{ __('admin.order_next') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.order_create') }}</h4>
                    <small class="text-muted">{{ __('admin.order_create_hint') }}</small>
                </div>
                <div class="card-body">
                    <dl class="row mb-1">
                        <dt class="col-6 fw-normal text-muted">{{ __('admin.order_step_customer') }}</dt>
                        <dd class="col-6 text-end">{{ $name ?: '—' }}</dd>

                        <dt class="col-6 fw-normal text-muted">{{ __('admin.order_phone') }}</dt>
                        <dd class="col-6 text-end">{{ $phone ?: '—' }}</dd>

                        <dt class="col-6 fw-normal text-muted">{{ __('admin.order_step_delivery') }}</dt>
                        <dd class="col-6 text-end">
                            {{ $delivery === 'pickup' ? __('admin.order_delivery_pickup') : __('admin.order_delivery_courier') }}
                        </dd>
                    </dl>

                    <hr>

                    @foreach ($lines as $line)
                        <div class="d-flex justify-content-between small">
                            <span class="text-truncate me-1">{{ $line['qty'] }} × {{ $line['name'] }}</span>
                            <span class="text-nowrap">{{ money($line['sum']) }}</span>
                        </div>
                    @endforeach

                    @if ($lines)
                        <hr>
                    @endif

                    <div class="d-flex justify-content-between">
                        <span class="text-muted">{{ __('admin.order_subtotal') }}</span>
                        <span>{{ money($subtotal) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">{{ __('admin.order_shipping') }}</span>
                        <span>{{ money($shipping) }}</span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between fs-5 fw-bolder">
                        <span>{{ __('admin.order_total') }}</span>
                        <span>{{ money($total) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
