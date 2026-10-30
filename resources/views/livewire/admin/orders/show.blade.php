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
            'box'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
            'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'file'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'minus'  => '<line x1="5" y1="12" x2="19" y2="12"/>',
            'trash'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'sync'   => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            'warn'   => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = [
        'new' => 'primary', 'viewed' => 'warning',
        'completed' => 'success', 'cancelled' => 'secondary',
    ];

    $initials = collect(preg_split('/\s+/', trim((string) $order->name)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('') ?: '?';
@endphp

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
        <div class="d-flex align-items-center gap-1">
            <a class="btn btn-sm btn-icon btn-outline-secondary"
               href="{{ route('admin.orders') }}" wire:navigate
               title="{{ __('admin.orders') }}">
                {!! $icon('back') !!}
            </a>

            <h4 class="mb-0">#{{ $order->number }}</h4>

            <span class="badge rounded-pill badge-light-{{ $tone[$order->status] ?? 'secondary' }}">
                {{ $statuses[$order->status] ?? $order->status }}
            </span>
            {{-- a method that has since been removed still has orders behind it --}}
            @php($method = \App\Models\PaymentMethod::withTranslation()->where('code', $order->payment)->first())

            <span class="badge rounded-pill badge-light-primary">
                {{ $method?->name ?: ($order->payment ?: '—') }}
            </span>

            @if ($order->is_paid)
                <span class="badge rounded-pill badge-light-success">{{ __('admin.paid') }}</span>
            @else
                <span class="badge rounded-pill badge-light-danger">{{ __('admin.unpaid') }}</span>
            @endif
        </div>

        <div class="d-flex align-items-center gap-1">
            <small class="text-muted d-flex align-items-center gap-25">
                {!! $icon('clock') !!}{{ $order->created_at->format('d.m.Y H:i') }}
            </small>

            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('admin.orders.invoice.preview', $order) }}" target="_blank" rel="noopener">
                {!! $icon('file', 'me-25') !!}{{ __('admin.invoice_open') }}
            </a>

            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.invoice', $order) }}">
                {!! $icon('file', 'me-25') !!}{{ __('admin.invoice_download') }}
            </a>

            @if ($this->editable())
                <button type="button" class="btn btn-sm btn-primary"
                        wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    {!! $icon('check', 'me-25') !!}{{ __('admin.order_save_changes') }}
                </button>
            @endif
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
            {{-- the lines are the order and the form at once: what is read here
                 is what a correction changes, with no editor to open first --}}
            <x-admin.list-card :title="__('admin.items')" :count="collect($lines)->sum('qty')">
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
                                            <span class="badge rounded-pill badge-light-success ms-50">{{ __('admin.order_in_stock', ['n' => $product->stock]) }}</span>
                                        @else
                                            <span class="badge rounded-pill badge-light-danger ms-50">{{ __('admin.order_no_stock') }}</span>
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
                                @php($item = $order->items->firstWhere('product_id', $line['id']))
                                @php($photo = $item?->product?->images->first())
                                @php($slug = \App\Models\ProductTranslation::where([
                                        'product_id' => $line['id'],
                                        'locale' => 'ka',
                                    ])->first()?->slug)
                                <tr wire:key="line-{{ $line['id'] }}">
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="order-thumb flex-shrink-0">
                                                @if ($photo)
                                                    <img src="{{ $photo->url() }}" alt="{{ $line['name'] }}"
                                                         loading="lazy" width="48" height="48">
                                                @else
                                                    <span class="avatar avatar-md bg-light-secondary">
                                                        <span class="avatar-content">{!! $icon('box') !!}</span>
                                                    </span>
                                                @endif
                                            </span>

                                            <span>
                                                @if ($slug)
                                                    <a class="fw-bolder d-block text-body"
                                                       href="{{ route('product', $slug) }}" target="_blank" rel="noopener">
                                                        {{ $line['name'] }}
                                                    </a>
                                                @else
                                                    <span class="fw-bolder d-block">{{ $line['name'] }}</span>
                                                @endif

                                                <small class="text-muted">
                                                    {{ $line['sku'] }}
                                                    @if ($item?->is_preorder)
                                                        · <span class="text-info">{{ __('admin.preorder') }}</span>
                                                        @if ($item->release_date)
                                                            {{ $item->release_date->format('d.m.Y') }}
                                                        @endif
                                                    @endif
                                                </small>
                                            </span>
                                        </div>
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
                            @if ($order->discount)
                                <tr>
                                    <td colspan="3" class="text-end text-muted">{{ __('checkout.discount') }}</td>
                                    <td class="text-end text-success">−{{ money($order->discount) }}</td>
                                    <td></td>
                                </tr>
                            @endif
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
            </x-admin.list-card>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.history') }}</h4>
                </div>

                <div class="card-body">
                    @if ($events->isNotEmpty())
                        <ul class="timeline mb-2">
                            @foreach ($events as $event)
                                <li class="timeline-item" wire:key="e-{{ $event->id }}">
                                    <span class="timeline-point timeline-point-indicator
                                                 timeline-point-{{ $event->type === 'status' ? ($tone[$event->to] ?? 'primary') : 'secondary' }}"></span>

                                    <div class="timeline-event">
                                        <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                            <h6 class="mb-0">
                                                @if ($event->type === 'status')
                                                    {{ $statuses[$event->from] ?? $event->from }}
                                                    →
                                                    <b>{{ $statuses[$event->to] ?? $event->to }}</b>
                                                @else
                                                    {{ __('admin.comment') }}
                                                @endif
                                            </h6>

                                            <span class="timeline-event-time">{{ $event->created_at->format('d.m.Y H:i') }}</span>
                                        </div>

                                        @if ($event->note)
                                            <p class="mb-0">{{ $event->note }}</p>
                                        @endif

                                        @if ($event->user)
                                            <small class="text-muted d-flex align-items-center gap-25">
                                                {!! $icon('user') !!}{{ $event->user->name }}
                                            </small>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">{{ __('admin.nothing_here') }}</p>
                    @endif

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

        <div class="col-lg-4">

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.status') }}</h4>
                </div>

                <div class="card-body">
                    <select class="form-select mb-1" wire:model.live="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <button type="button" class="btn btn-primary w-100"
                            wire:click="changeStatus" @disabled($status === $order->status)>
                        {{ __('admin.save') }}
                    </button>

                    @unless ($order->is_paid)
                        @if ($transaction)
                            <button type="button" class="btn btn-primary w-100 mt-1"
                                    wire:click="askBank"
                                    wire:loading.attr="disabled" wire:target="askBank">
                                <span wire:loading.remove wire:target="askBank">
                                    {!! $icon('money', 'me-25') !!}{{ __('admin.pay_ask_bank') }}
                                </span>
                                <span wire:loading wire:target="askBank">{{ __('admin.pay_asking') }}</span>
                            </button>

                            <small class="d-block text-muted mt-50">
                                {{ __('admin.pay_last_checked', [
                                    'driver' => $transaction->driver,
                                    'when' => $transaction->checked_at?->diffForHumans() ?: __('admin.pay_never_checked'),
                                ]) }}
                            </small>
                        @endif

                        <button type="button" class="btn btn-outline-success w-100 mt-1"
                                wire:click="markPaid"
                                wire:confirm="{{ __('admin.mark_paid_confirm') }}">
                            {!! $icon('money', 'me-25') !!}{{ __('admin.mark_paid') }}
                        </button>
                    @endunless
                </div>
            </div>

            <div @class(['card', 'border-primary' => $payLink])>
                <div class="card-header">
                    <h4 class="card-title mb-0 d-flex align-items-center gap-50">
                        @if ($payLink) {!! $icon('link') !!} @endif
                        {{ $payLink ? __('admin.pay_online') : __('admin.payment') }}
                    </h4>
                </div>

                <div class="card-body">
                    <label class="form-label" for="orderPayment">{{ __('admin.payment') }}</label>
                    <select id="orderPayment" class="form-select @error('payment') is-invalid @enderror"
                            wire:model.live="payment">
                        @foreach ($methods as $method)
                            <option value="{{ $method->code }}" @disabled(! ($fits[$method->code] ?? true))>
                                {{ $method->name }}
                                @unless ($fits[$method->code] ?? true) — {{ __('admin.order_method_unavailable') }} @endunless
                            </option>
                        @endforeach
                    </select>
                    @error('payment') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    {{-- The link is issued against the order as it stands, so a
                         method picked but not yet saved has none. --}}
                    @if ($payment !== $order->payment)
                        <small class="d-block text-warning mt-50">
                            {{ __('admin.pay_link_after_save') }}
                        </small>
                    @endif

                    @if ($payLink)
                        <hr class="my-1">

                        <p class="text-muted small">
                            {{ __('admin.pay_link_note', ['days' => \App\Livewire\Admin\Orders\Show::LINK_DAYS]) }}
                        </p>

                        <div class="input-group mb-1">
                            <input type="text" class="form-control" readonly
                                   value="{{ $payLink }}"
                                   onfocus="this.select()">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="
                                        const field = this.previousElementSibling;
                                        field.select();
                                        field.setSelectionRange(0, 99999);
                                        if (navigator.clipboard) {
                                            navigator.clipboard.writeText(field.value);
                                        } else {
                                            document.execCommand('copy');
                                        }
                                        this.textContent = @js(__('admin.copied'));
                                    ">
                                {{ __('admin.copy') }}
                            </button>
                        </div>

                        <button type="button" class="btn btn-outline-primary w-100 mb-1"
                                wire:click="sendPayLink" wire:loading.attr="disabled"
                                wire:confirm="{{ __('admin.pay_link_sms_confirm', ['phone' => $order->phone]) }}">
                            {!! $icon('phone', 'me-25') !!}{{ __('admin.pay_link_sms') }}
                        </button>

                        <a class="btn btn-primary w-100" href="{{ $payLink }}" target="_blank" rel="noopener">
                            {!! $icon('money', 'me-25') !!}{{ __('admin.pay_here') }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.customer') }}</h4>
                </div>

                <div class="card-body">
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <div class="avatar avatar-md bg-light-primary">
                            <span class="avatar-content">{{ $initials }}</span>
                        </div>

                        <div>
                            @if ($order->user)
                                <a class="fw-bolder text-body d-block"
                                   href="{{ route('admin.users.show', $order->user) }}" wire:navigate>
                                    {{ $order->name }}
                                </a>
                                <small class="text-muted">{{ __('admin.registered_customer') }}</small>
                            @else
                                <span class="fw-bolder d-block">{{ $order->name }}</span>
                                <small class="text-muted">{{ __('admin.guest_customer') }}</small>
                            @endif
                        </div>
                    </div>

                    <label class="form-label" for="orderName">{{ __('checkout.name') }}</label>
                    <input type="text" id="orderName" class="form-control @error('name') is-invalid @enderror"
                           wire:model="name">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    <label class="form-label mt-1" for="orderPhone">{{ __('checkout.phone') }}</label>
                    {{-- the button dials what the order already holds, not what is
                         being typed into the field beside it --}}
                    <div class="input-group">
                        <input type="text" id="orderPhone" class="form-control @error('phone') is-invalid @enderror"
                               wire:model="phone">
                        <a class="btn btn-outline-primary" href="{{ \App\Support\Phone::link($order->phone) }}"
                           title="{{ $order->phone }}">{!! $icon('phone') !!}</a>
                    </div>
                    @error('phone') <div class="text-danger small mt-25">{{ $message }}</div> @enderror

                    <label class="form-label mt-1" for="orderEmail">{{ __('checkout.email') }}</label>
                    <div class="input-group">
                        <input type="email" id="orderEmail" class="form-control @error('email') is-invalid @enderror"
                               wire:model="email">
                        @if ($order->email)
                            <a class="btn btn-outline-secondary" href="mailto:{{ $order->email }}">{!! $icon('mail') !!}</a>
                        @endif
                    </div>
                    @error('email') <div class="text-danger small mt-25">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.delivery') }}</h4>
                </div>

                <div class="card-body">
                    <select class="form-select" wire:model.live="delivery">
                        <option value="courier">{{ __('admin.order_delivery_courier') }}</option>
                        <option value="pickup">{{ __('admin.order_delivery_pickup') }}</option>
                    </select>

                    @if ($delivery === 'courier')
                        {{-- no city to pick: the address says where it goes --}}
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


            @if ($this->editable())
                <div class="card">
                    <div class="card-body">
                        <button type="button" class="btn btn-primary w-100"
                                wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            {!! $icon('check', 'me-25') !!}{{ __('admin.order_save_changes') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
