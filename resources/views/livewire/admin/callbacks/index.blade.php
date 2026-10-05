@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'check'  => '<polyline points="20 6 9 17 4 12"/>',
            'x'      => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'trash'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'box'    => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
            'link'   => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $tone = ['new' => 'danger', 'called' => 'info', 'closed' => 'secondary'];
@endphp

<div>
    <div class="d-flex flex-wrap gap-1 mb-2">
        @foreach ($statuses as $key => $label)
            <button type="button"
                    @class(['btn btn-sm',
                        'btn-'.$tone[$key] => $status === $key,
                        'btn-outline-'.$tone[$key] => $status !== $key])
                    wire:click="$set('status', '{{ $status === $key ? '' : $key }}')">
                {{ $label }}
                @if ($counts[$key] ?? 0)
                    <span class="badge bg-white text-dark ms-50">{{ $counts[$key] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body pb-1">
            <div class="input-group input-group-merge" style="max-width:360px">
                <span class="input-group-text">{!! $icon('search') !!}</span>
                <input type="text" class="form-control"
                       placeholder="{{ __('admin.cb_search') }}"
                       wire:model.live.debounce.400ms="search">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:150px">{{ __('admin.cb_when') }}</th>
                        <th>{{ __('admin.customer') }}</th>
                        <th>{{ __('admin.cb_product') }}</th>
                        <th class="text-center" style="width:120px">{{ __('admin.status') }}</th>
                        <th class="text-end" style="width:180px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $request)
                        <tr wire:key="cb-{{ $request->id }}" @class(['table-active' => $open === $request->id])>
                            <td>
                                <span class="d-block">{{ $request->created_at->format('d.m.Y H:i') }}</span>
                                <small class="text-muted">{{ $request->created_at->diffForHumans() }}</small>
                            </td>

                            <td>
                                <button type="button" class="btn btn-link p-0 fw-bolder text-body"
                                        wire:click="toggle({{ $request->id }})">
                                    {{ $request->name }}
                                </button>

                                <small class="d-block">
                                    <a href="tel:{{ $request->phone }}" class="d-inline-flex align-items-center gap-25">
                                        {!! $icon('phone') !!}{{ $request->phone }}
                                    </a>
                                </small>

                                @if ($request->user)
                                    <small class="badge badge-light-secondary">{{ __('admin.registered_customer') }}</small>
                                @endif
                            </td>

                            <td class="text-muted">
                                @if ($request->product)
                                    <a href="{{ route('admin.products.edit', $request->product) }}">
                                        {{ $request->product->name }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge badge-light-{{ $tone[$request->status] ?? 'secondary' }}">
                                    {{ $statuses[$request->status] ?? $request->status }}
                                </span>
                            </td>

                            <td class="text-end text-nowrap">
                                @if ($request->status !== 'called')
                                    <button type="button" class="btn btn-sm btn-outline-info"
                                            wire:click="setStatus({{ $request->id }}, 'called')">
                                        {!! $icon('phone', 'me-25') !!}{{ __('admin.cb_mark_called') }}
                                    </button>
                                @endif

                                @if ($request->status !== 'closed')
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                            wire:click="setStatus({{ $request->id }}, 'closed')"
                                            title="{{ __('admin.cb_close') }}">
                                        {!! $icon('check') !!}
                                    </button>
                                @endif

                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                        data-confirm="{{ __('admin.confirm_delete') }}"
                                        data-confirm-action="delete"
                                        data-confirm-arg="{{ $request->id }}">
                                    {!! $icon('trash') !!}
                                </button>
                            </td>
                        </tr>

                        @if ($open === $request->id)
                            <tr wire:key="cb-open-{{ $request->id }}">
                                <td colspan="5" class="bg-light">
                                    @if ($request->comment)
                                        <p class="mb-1"><b>{{ __('admin.cb_comment') }}:</b> {{ $request->comment }}</p>
                                    @endif

                                    @if ($request->page)
                                        <p class="mb-1 small">
                                            <a href="{{ $request->page }}" target="_blank" rel="noopener"
                                               class="d-inline-flex align-items-center gap-25">
                                                {!! $icon('link') !!}{{ __('admin.cb_page') }}
                                            </a>
                                        </p>
                                    @endif

                                    @if ($request->handler)
                                        <p class="mb-1 small text-muted">
                                            {{ __('admin.cb_handled_by', [
                                                'name' => $request->handler->name,
                                                'when' => $request->handled_at?->format('d.m.Y H:i'),
                                            ]) }}
                                        </p>
                                    @endif

                                    <div class="d-flex align-items-start gap-1">
                                        <textarea class="form-control" rows="2"
                                                  placeholder="{{ __('admin.cb_note') }}"
                                                  wire:model="note"></textarea>
                                        <button type="button" class="btn btn-outline-primary text-nowrap"
                                                wire:click="saveNote({{ $request->id }})">
                                            {{ __('admin.save') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('box') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($requests->hasPages())
            <div class="card-body pt-1">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
