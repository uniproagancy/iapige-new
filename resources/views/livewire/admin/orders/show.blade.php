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
            'edit'   => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'box'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
            'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'file'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
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
			@php($payment_id = \App\Models\PaymentMethod::where('code', $order->payment)->first()->id)
			@php($payment_name = \App\Models\PaymentMethodTranslation::where('payment_method_id', $payment_id)->where('locale', 'ka')->first()->name)
            <span class="badge rounded-pill badge-light-primary">
                {{ $payment_name }}
            </span>

            @if ($order->is_paid)
                <span class="badge rounded-pill badge-light-success">{{ __('admin.paid') }}</span>
            @else
                <span class="badge rounded-pill badge-light-warning">{{ __('admin.unpaid') }}</span>
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

            @unless (in_array($order->status, ['cancelled', 'returned'], true))
                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.edit', $order) }}" wire:navigate>
                    {!! $icon('edit', 'me-25') !!}{{ __('admin.order_edit') }}
                </a>
            @endunless
        </div>
    </div>

    <div class="row">

        <div class="col-lg-8">
            <x-admin.list-card :title="__('admin.items')" :count="$order->items->sum('qty')">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('admin.name') }}</th>
                                <th class="text-center" style="width:90px">{{ __('admin.qty') }}</th>
                                <th class="text-end" style="width:120px">{{ __('admin.price') }}</th>
                                <th class="text-end" style="width:120px">{{ __('admin.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                @php($photo = $item->product?->images->first())
								@php($slug = \App\Models\ProductTranslation::where([
										'product_id' => $item->product_id,
										'locale' => 'ka',
									])->first()->slug)
                                <tr wire:key="i-{{ $item->id }}">
                                    <td>
                                        <div class="d-flex align-items-center gap-1">

                                            <span class="order-thumb flex-shrink-0">
                                                @if ($photo)
                                                    <a href="#">
                                                        <img src="{{ $photo->url() }}" alt="{{ $item->name }}"
                                                             loading="lazy" width="48" height="48">
                                                    </a>
                                                @else
                                                    <span class="avatar avatar-md bg-light-secondary">
                                                        <span class="avatar-content">{!! $icon('box') !!}</span>
                                                    </span>
                                                @endif
                                            </span>

                                            <span>
                                                @if ($item->product_id)
                                                    <a class="fw-bolder d-block text-body"
                                                       href="{{ route('product', $slug) }}" target="_blank" wire:navigate>
                                                        {{ $item->name }}
                                                    </a>
                                                @else
                                                    <span class="fw-bolder d-block">{{ $item->name }}</span>
                                                @endif

                                                <small class="text-muted">
                                                    {{ $item->sku }}
                                                    @if ($item->is_preorder)
                                                        · <span class="text-info">{{ __('admin.preorder') }}</span>
                                                        @if ($item->release_date)
                                                            {{ $item->release_date->format('d.m.Y') }}
                                                        @endif
                                                    @endif
                                                </small>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-center">{{ $item->qty }}</td>
                                    <td class="text-end">{{ money($item->price) }}</td>
                                    <td class="text-end fw-bolder">{{ money($item->price * $item->qty) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-muted">{{ __('checkout.subtotal') }}</td>
                                <td class="text-end">{{ money($order->subtotal) }}</td>
                            </tr>
                            @if ($order->discount)
                                <tr>
                                    <td colspan="3" class="text-end text-muted">{{ __('checkout.discount') }}</td>
                                    <td class="text-end text-success">−{{ money($order->discount) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td colspan="3" class="text-end text-muted">{{ __('checkout.shipping') }}</td>
                                <td class="text-end">{{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end fw-bolder">{{ __('admin.total') }}</td>
                                <td class="text-end fw-bolder fs-5">{{ money($order->total) }}</td>
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
                        <button type="button" class="btn btn-outline-success w-100 mt-1"
                                wire:click="markPaid"
                                wire:confirm="{{ __('admin.mark_paid_confirm') }}">
                            {!! $icon('money', 'me-25') !!}{{ __('admin.mark_paid') }}
                        </button>
                    @endunless
                </div>
            </div>

            @if ($payLink)
                <div class="card border-primary">
                    <div class="card-header">
                        <h4 class="card-title mb-0 d-flex align-items-center gap-50">
                            {!! $icon('link') !!}{{ __('admin.pay_online') }}
                        </h4>
                    </div>

                    <div class="card-body">
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
                    </div>
                </div>
            @endif

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

                    <a class="btn btn-outline-primary w-100 mb-1 d-flex align-items-center justify-content-center gap-50"
                       href="{{ \App\Support\Phone::link($order->phone) }}">
                        {!! $icon('phone') !!}{{ $order->phone }}
                    </a>

                    @if ($order->email)
                        <a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-50"
                           href="mailto:{{ $order->email }}">
                            {!! $icon('mail') !!}<span class="text-truncate">{{ $order->email }}</span>
                        </a>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('admin.delivery') }}</h4>
                </div>

                <div class="card-body">
                    <div class="d-flex align-items-start gap-50">
                        <span class="text-muted mt-25">{!! $icon('pin') !!}</span>

                        <span>
                            <b>{{ $order->city ?: '—' }}</b>
                            @if ($order->address)
                                <span class="d-block text-muted">{{ $order->address }}</span>
                            @endif
                        </span>
                    </div>

                    @if ($order->comment)
                        <div class="alert alert-warning mt-1 mb-0">
                            <div class="alert-body">{{ $order->comment }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
