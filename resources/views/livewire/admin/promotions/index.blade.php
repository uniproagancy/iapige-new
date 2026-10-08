@php
    $icon = function (string $name) {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'   => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'check'  => '<polyline points="20 6 9 17 4 12"/>',
        ];

        return '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            .' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            .($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    {{-- ---------------------------------------------------------- campaigns --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center gap-1">
            @forelse ($promotions as $p)
                <button type="button"
                        @class([
                            'btn btn-sm',
                            'btn-primary' => $p->id === $promotionId,
                            'btn-outline-secondary' => $p->id !== $promotionId,
                        ])
                        wire:click="select({{ $p->id }})">
                    {{ $p->title ?: $p->code }}
                    @unless ($p->is_active)
                        <span class="badge badge-light-secondary ms-50">{{ __('admin.inactive') }}</span>
                    @endunless
                </button>
            @empty
                <span class="text-muted">{{ __('admin.promo_none_yet') }}</span>
            @endforelse

            <div class="ms-auto d-flex gap-1">
                @if ($promotion)
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="editPromotion">
                        {!! $icon('edit') !!}
                    </button>
                    <button type="button"
                            @class(['btn btn-sm', 'btn-success' => $promotion->is_active, 'btn-outline-success' => ! $promotion->is_active])
                            wire:click="toggleActive">
                        {{ $promotion->is_active ? __('admin.active') : __('admin.inactive') }}
                    </button>
                @endif
                <button type="button" class="btn btn-sm btn-primary" wire:click="create">
                    {!! $icon('plus') !!} {{ __('admin.add') }}
                </button>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------- the form --}}
    @if ($showForm)
        <div class="card">
            <div class="card-body">
                <div class="row g-1">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('admin.promo_code') }}</label>
                        <input type="text" class="form-control @error('code') is-invalid @enderror"
                               wire:model="code" placeholder="week-deal">
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('admin.promo_title') }}</label>
                        <input type="text" class="form-control" wire:model="title">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('admin.promo_starts') }}</label>
                        <input type="datetime-local" class="form-control" wire:model="starts_at">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('admin.promo_ends') }}</label>
                        <input type="datetime-local" class="form-control @error('ends_at') is-invalid @enderror"
                               wire:model="ends_at">
                        @error('ends_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">{{ __('admin.sort') }}</label>
                        <input type="number" class="form-control" wire:model="sort_order">
                    </div>
                </div>

                <div class="d-flex align-items-center gap-1 mt-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="promoActive" wire:model="is_active">
                        <label class="form-check-label" for="promoActive">{{ __('admin.active') }}</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="savePromotion">
                        {{ __('admin.save') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showForm', false)">
                        {{ __('admin.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($promotion)
        {{-- ------------------------------------------------------ add a product --}}
        <div class="card">
            <div class="card-body">
                <div class="input-group input-group-merge" style="max-width:420px">
                    <span class="input-group-text">{!! $icon('search') !!}</span>
                    <input type="text" class="form-control" placeholder="{{ __('admin.promo_search') }}"
                           wire:model.live.debounce.400ms="search">
                </div>

                @if ($found->isNotEmpty())
                    <div class="list-group mt-1">
                        @foreach ($found as $p)
                            <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-1"
                                    wire:click="add({{ $p->id }})" wire:key="found-{{ $p->id }}">
                                {!! $icon('plus') !!}
                                <span>{{ $p->name ?: $p->sku }}</span>
                                <small class="text-muted ms-1">{{ $p->sku }}</small>
                                <span class="ms-auto fw-bold">{{ money($p->price) }}</span>
                            </button>
                        @endforeach
                    </div>
                @elseif (mb_strlen(trim($search)) >= 2)
                    <p class="text-muted mt-1 mb-0">{{ __('admin.promo_nothing_found') }}</p>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------ the products --}}
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('admin.product') }}</th>
                            <th style="width:130px">{{ __('admin.price') }}</th>
                            <th style="width:160px">{{ __('admin.promo_price') }}</th>
                            <th style="width:130px">{{ __('admin.promo_percent') }}</th>
                            <th style="width:100px">{{ __('admin.sort') }}</th>
                            <th style="width:120px">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $p)
                            <tr wire:key="row-{{ $p->id }}">
                                <td>
                                    <a href="{{ route('admin.products.edit', $p) }}">{{ $p->name ?: $p->sku }}</a>
                                    <small class="d-block text-muted">{{ $p->sku }}</small>
                                </td>
                                <td>{{ money($p->price) }}</td>
                                <td>
                                    <input type="number" step="0.01" class="form-control form-control-sm @error('price.'.$p->id) is-invalid @enderror"
                                           wire:model="price.{{ $p->id }}">
                                    @error('price.'.$p->id) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm @error('percent.'.$p->id) is-invalid @enderror"
                                           wire:model="percent.{{ $p->id }}">
                                    @error('percent.'.$p->id) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm" wire:model="order.{{ $p->id }}">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveRow({{ $p->id }})">
                                        {!! $icon('check') !!}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="remove({{ $p->id }})"
                                            wire:confirm="{{ __('admin.sure') }}">
                                        {!! $icon('trash') !!}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-2">{{ __('admin.promo_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted">{{ __('admin.promo_hint') }}</p>
    @endif
</div>
