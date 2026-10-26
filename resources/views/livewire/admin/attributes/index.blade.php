@php
    /* icons are inlined so the controls work whether or not feather loads */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'x'      => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'edit'   => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'list'   => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'zap'    => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'sliders'=> '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>

    <x-admin.list-card :title="__('admin.attributes')" :count="$counts['total']">
        <x-slot:actions>
            <button type="button" class="btn btn-outline-primary"
                    wire:click="enableUseful"
                    wire:confirm="{{ __('admin.enable_useful_confirm') }}">
                {!! $icon('zap', 'me-25') !!}{{ __('admin.enable_useful') }}
            </button>

            <button type="button" class="btn btn-outline-secondary"
                    wire:click="disableAll"
                    wire:confirm="{{ __('admin.disable_all_confirm') }}">
                {{ __('admin.disable_all') }}
            </button>
        </x-slot:actions>

        <x-slot:filters>
            <div class="col-lg-4 col-md-6">
                <label class="form-label">{{ __('admin.search') }}</label>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{!! $icon('search') !!}</span>
                    <input type="text" class="form-control"
                           placeholder="{{ __('admin.search') }}"
                           wire:model.live.debounce.400ms="search">
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">{{ __('admin.status') }}</label>
                <select class="form-select" wire:model.live="stateFilter">
                    <option value="">{{ __('admin.all') }}</option>
                    <option value="on">{{ __('admin.filter_on') }}</option>
                    <option value="off">{{ __('admin.filter_off') }}</option>
                    <option value="unused">{{ __('admin.no_values') }}</option>
                </select>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label">{{ __('admin.sort_by') }}</label>
                <select class="form-select" wire:model.live="sort">
                    <option value="products">{{ __('admin.sort_reach') }}</option>
                    <option value="values">{{ __('admin.sort_values') }}</option>
                    <option value="name">{{ __('admin.sort_code') }}</option>
                </select>
            </div>

            @if ($this->hasFilters())
                <div class="col-lg-2 col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary w-100" wire:click="resetFilters">
                        {!! $icon('x', 'me-25') !!}{{ __('admin.reset') }}
                    </button>
                </div>
            @endif

            <div class="col-12 text-muted small">
                {{ __('admin.filters_on_n', ['on' => $counts['on'], 'total' => $counts['total']]) }}
            </div>
        </x-slot:filters>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th style="width:140px">{{ __('admin.code') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.values') }}</th>
                        <th class="text-center" style="width:120px">{{ __('admin.products') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.filter') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.variant') }}</th>
                        <th class="text-end" style="width:130px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $attribute)
                        @php $reach = $usage[$attribute->id] ?? 0; @endphp

                        <tr wire:key="attr-{{ $attribute->id }}" @class(['opacity-75' => ! $reach])>
                            <td class="fw-bolder">{{ $attribute->name ?: $attribute->code }}</td>

                            <td><code class="text-muted">{{ $attribute->code }}</code></td>

                            <td class="text-center">
                                <button type="button"
                                        @class(['btn btn-sm', 'btn-flat-primary' => $valuesFor === $attribute->id, 'btn-flat-secondary' => $valuesFor !== $attribute->id])
                                        wire:click="showValues({{ $attribute->id }})"
                                        @disabled(! $attribute->values_count)>
                                    {{ $attribute->values_count }}
                                </button>
                            </td>

                            <td class="text-center">
                                @if ($reach)
                                    <span class="badge rounded-pill badge-light-secondary">{{ $reach }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleFilterable({{ $attribute->id }})"
                                           @checked($attribute->is_filterable)>
                                </div>
                            </td>

                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleVariant({{ $attribute->id }})"
                                           @checked($attribute->is_variant)>
                                </div>

                                @if ($attribute->is_variant)
                                    <small class="d-block text-muted">
                                        {{ ($shelvesFor[$attribute->id] ?? 0)
                                            ? __('admin.attr_categories_some', ['count' => $shelvesFor[$attribute->id]])
                                            : __('admin.attr_categories_all') }}
                                    </small>
                                @endif
                            </td>

                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                        wire:click="edit({{ $attribute->id }})"
                                        title="{{ __('admin.edit') }}">
                                    {!! $icon('edit') !!}
                                </button>

                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                        wire:click="delete({{ $attribute->id }})"
                                        wire:confirm="{{ __('admin.delete_attribute_confirm') }}"
                                        title="{{ __('admin.delete') }}">
                                    {!! $icon('trash') !!}
                                </button>
                            </td>
                        </tr>

                        @if ($valuesFor === $attribute->id)
                            <tr wire:key="vals-{{ $attribute->id }}">
                                <td colspan="7" class="bg-transparent">
                                    <div class="d-flex flex-wrap gap-1 py-1">
                                        @forelse ($values as $value)
                                            <span class="badge rounded-pill badge-light-secondary d-inline-flex align-items-center gap-25"
                                                  wire:key="val-{{ $value->id }}">
                                                @if ($value->color_hex)
                                                    <span style="width:10px;height:10px;border-radius:50%;background:{{ $value->color_hex }};display:inline-block"></span>
                                                @endif
                                                {{ $value->label ?: $value->code }}
                                                <button type="button" class="btn btn-sm btn-icon p-0 ms-25 text-danger"
                                                        wire:click="deleteValue({{ $value->id }})"
                                                        wire:confirm="{{ __('admin.confirm_delete') }}">×</button>
                                            </span>
                                        @empty
                                            <span class="text-muted">{{ __('admin.no_values') }}</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('sliders') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-body pt-1">{{ $rows->links() }}</div>
        @endif
    </div>

    <div class="alert alert-primary">
        <div class="alert-body d-flex align-items-start gap-1">
            {!! $icon('sliders') !!}
            <span>{{ __('admin.attributes_hint') }}</span>
        </div>
    </x-admin.list-card>

    @if ($showForm)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(34,41,47,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('admin.edit') }}</h5>
                            <button type="button" class="btn-close" wire:click="$set('showForm', false)"></button>
                        </div>

                        <div class="modal-body">
                            @foreach ($languages as $language)
                                <div class="mb-1">
                                    <label class="form-label">
                                        {{ __('admin.name') }} ({{ strtoupper($language->code) }})
                                    </label>
                                    <input type="text"
                                           class="form-control @error("translations.{$language->code}.name") is-invalid @enderror"
                                           wire:model="translations.{{ $language->code }}.name">
                                    @error("translations.{$language->code}.name")
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach

                            <div class="row">
                                <div class="col-6 mb-1">
                                    <label class="form-label">{{ __('admin.code') }}</label>
                                    <input type="text" class="form-control @error('code') is-invalid @enderror"
                                           wire:model="code">
                                    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-3 mb-1">
                                    <label class="form-label">{{ __('admin.type') }}</label>
                                    <select class="form-select" wire:model="type">
                                        <option value="select">select</option>
                                        <option value="color">color</option>
                                        <option value="text">text</option>
                                    </select>
                                </div>

                                <div class="col-3 mb-1">
                                    <label class="form-label">{{ __('admin.sort_order') }}</label>
                                    <input type="number" class="form-control" wire:model="sort_order" min="0">
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="a-filter" wire:model="is_filterable">
                                    <label class="form-check-label" for="a-filter">{{ __('admin.filter') }}</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="a-variant" wire:model="is_variant">
                                    <label class="form-check-label" for="a-variant">{{ __('admin.variant') }}</label>
                                </div>
                            </div>

                            <small class="text-muted d-block mt-1">{{ __('admin.variant_hint') }}</small>

                            <hr>

                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>{{ __('admin.attr_categories') }}</span>

                                @if (count($categories))
                                    <button type="button" class="btn btn-sm btn-flat-secondary py-0"
                                            wire:click="$set('categories', [])">{{ __('admin.reset') }}</button>
                                @endif
                            </label>

                            <select class="form-select" wire:model="categories" multiple size="9">
                                @foreach ($shelves as $shelf)
                                    <option value="{{ $shelf['id'] }}">{{ $shelf['name'] }}</option>
                                @endforeach
                            </select>

                            <small class="text-muted d-block mt-50">
                                {{ count($categories)
                                    ? __('admin.attr_categories_some', ['count' => count($categories)])
                                    : __('admin.attr_categories_all') }}
                            </small>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" wire:click="$set('showForm', false)">
                                {{ __('admin.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                                {{ __('admin.save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
