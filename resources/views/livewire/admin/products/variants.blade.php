@php
    /* inlined, as on the product form itself, so the controls work whether or
       not feather loads */
    $icon = function (string $name) {
        $paths = [
            'edit'  => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'x'     => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        ];

        return '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div class="card">
    <div class="card-header pb-0">
        <h4 class="card-title mb-0">{{ __('admin.variants') }}</h4>
        <small class="text-muted">{{ __('admin.variants_hint') }}</small>
    </div>

    <div class="card-body">

        <label class="form-label" for="v-group">{{ __('admin.variant_group') }}</label>

        <div class="input-group mb-50">
            <input id="v-group" type="text" class="form-control @error('group') is-invalid @enderror"
                   wire:model="group" placeholder="{{ __('admin.variant_group_empty') }}">
            <button type="button" class="btn btn-outline-primary" wire:click="saveGroup">
                {{ __('admin.save') }}
            </button>
            @error('group') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <small class="text-muted d-block mb-1">{{ __('admin.variant_group_hint') }}</small>

        <hr>

        <h6 class="mb-50">{{ __('admin.variants_family', ['count' => $family->count()]) }}</h6>

        <div class="mb-1">
            @foreach ($family as $member)
                <div class="d-flex justify-content-between align-items-center py-50 border-bottom"
                     wire:key="fam-{{ $member->id }}">
                    <span class="text-truncate me-1">
                        <span @class(['fw-bolder' => $member->is($product)])>{{ $member->name }}</span>

                        @if ($member->is($product))
                            <span class="badge rounded-pill badge-light-primary ms-25">{{ __('admin.variants_this') }}</span>
                        @endif

                        <small class="d-block text-muted">
                            <span class="font-monospace">{{ $member->sku }}</span> · {{ money($member->price) }}

                            {{-- only what tells the family apart; the rest of a
                                 product's values belong on the specs table --}}
                            @foreach ($member->attributeValues as $value)
                                @if ($value->attribute?->is_variant)
                                    · {{ $value->attribute->name ?: $value->attribute->code }}: {{ $value->label }}
                                @endif
                            @endforeach
                        </small>
                    </span>

                    <span class="text-nowrap">
                        @unless ($member->is($product))
                            <a class="btn btn-sm btn-icon btn-flat-secondary"
                               href="{{ route('admin.products.edit', $member) }}" wire:navigate
                               title="{{ __('admin.edit') }}">{!! $icon('edit') !!}</a>
                        @endunless

                        @if ($product->variant_group)
                            <button type="button" class="btn btn-sm btn-icon btn-flat-danger"
                                    wire:click="leave({{ $member->id }})"
                                    wire:confirm="{{ __('admin.variants_leave_sure') }}"
                                    title="{{ __('admin.variants_leave') }}">{!! $icon('x') !!}</button>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        <label class="form-label" for="v-find">{{ __('admin.variants_add') }}</label>

        <input id="v-find" type="text" class="form-control mb-50"
               wire:model.live.debounce.400ms="find"
               placeholder="{{ __('admin.variants_find') }}">

        @if ($found->count())
            <div class="mb-1">
                @foreach ($found as $one)
                    <div class="d-flex justify-content-between align-items-center py-25 border-bottom"
                         wire:key="found-{{ $one->id }}">
                        <span class="text-truncate me-1">
                            {{ $one->name }}
                            <small class="d-block text-muted font-monospace">{{ $one->sku }}</small>
                        </span>

                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                wire:click="join({{ $one->id }})">{{ __('admin.variants_join') }}</button>
                    </div>
                @endforeach
            </div>
        @elseif (mb_strlen(trim($find)) >= 2)
            <p class="text-muted small mb-1">{{ __('admin.nothing_here') }}</p>
        @endif

        <hr>

        <h6 class="mb-50">{{ __('admin.variants_values') }}</h6>

        @forelse ($offered as $attribute)
            <div class="mb-1" wire:key="attr-{{ $attribute->id }}">
                <label class="form-label" for="v-attr-{{ $attribute->id }}">
                    {{ $attribute->name ?: $attribute->code }}
                </label>

                <select id="v-attr-{{ $attribute->id }}" class="form-select"
                        wire:model="values.{{ $attribute->id }}">
                    <option value="">—</option>
                    @foreach ($attribute->values as $value)
                        <option value="{{ $value->id }}">{{ $value->label ?: $value->code }}</option>
                    @endforeach
                </select>

                <div class="input-group input-group-sm mt-50">
                    <input type="text" class="form-control"
                           placeholder="{{ __('admin.variants_new_value') }}"
                           wire:keydown.enter="addValue({{ $attribute->id }}, $event.target.value)">
                </div>
            </div>
        @empty
            <p class="text-muted mb-1">{{ __('admin.variants_none_offered') }}</p>
        @endforelse

        @if ($offered->count())
            <button type="button" class="btn btn-primary w-100"
                    wire:click="saveValues" wire:loading.attr="disabled" wire:target="saveValues">
                {{ __('admin.save') }}
            </button>
        @endif
    </div>
</div>
