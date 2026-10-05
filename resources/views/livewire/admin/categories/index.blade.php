@php
    /**
     * Icons are inlined instead of drawn by feather: the table's only controls
     * are icon buttons, so a script that fails to load would leave the page
     * unusable rather than merely plain.
     */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'     => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'grip'     => '<line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/>',
            'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'child'    => '<polyline points="15 10 20 15 15 20"/><path d="M4 4v7a4 4 0 0 0 4 4h12"/>',
            'check'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'circle'   => '<circle cx="12" cy="12" r="10"/>',
            'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    {{-- ------------------------------------------------------------ filters --}}
    <div class="card">
        <div class="card-body pb-1">
            <div class="row g-1">
                <div class="col-lg-4 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text">{!! $icon('search') !!}</span>
                        <input type="text" class="form-control"
                               placeholder="{{ __('admin.search') }}"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select class="form-select" wire:model.live="rootFilter">
                        <option value="">{{ __('admin.all_sections') }}</option>
                        @foreach ($roots as $root)
                            <option value="{{ $root->id }}">{{ $root->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <select class="form-select" wire:model.live="stateFilter">
                        <option value="">{{ __('admin.any_state') }}</option>
                        <option value="active">{{ __('admin.active') }}</option>
                        <option value="hidden">{{ __('admin.hidden') }}</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <select class="form-select" wire:model.live="productFilter">
                        <option value="">{{ __('admin.any_products') }}</option>
                        <option value="filled">{{ __('admin.with_products') }}</option>
                        <option value="empty">{{ __('admin.without_products') }}</option>
                    </select>
                </div>

                <div class="col-lg-1 col-md-4">
                    <select class="form-select" wire:model.live="placeFilter">
                        <option value="">{{ __('admin.anywhere') }}</option>
                        <option value="menu">{{ __('admin.in_menu') }}</option>
                        <option value="home">{{ __('admin.on_home') }}</option>
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-1 pb-1">
                <small class="text-muted d-flex align-items-center gap-1">
                    {{ __('admin.showing', ['shown' => count($tree), 'total' => $total]) }}

                    @if ($this->hasFilters())
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="resetFilters">
                            {!! $icon('x', 'me-25') !!}{{ __('admin.reset') }}
                        </button>
                    @endif
                </small>

                <button type="button" class="btn btn-primary btn-sm" wire:click="create">
                    {!! $icon('plus', 'me-25') !!}{{ __('admin.create') }}
                </button>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ tree --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover cat-table mb-0">
                <thead>
                    <tr>
                        <th style="width:44px"></th>
                        <th>{{ __('admin.name') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.products') }}</th>
                        <th class="text-center" style="width:90px">{{ __('admin.in_menu') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.on_home') }}</th>
                        <th class="text-center" style="width:90px">{{ __('admin.active') }}</th>
                        <th class="text-end" style="width:150px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>

                {{-- rows carry their parent, so a drag never escapes its own level --}}
                <tbody data-sortable-tree>
                    @forelse ($tree as $row)
                        <tr wire:key="cat-{{ $row['id'] }}"
                            data-id="{{ $row['id'] }}"
                            data-parent="{{ $row['parent'] ?? 0 }}"
                            @class(['cat-row', 'is-dimmed' => $row['dimmed']])>

                            <td class="cat-grip" title="{{ __('admin.drag_to_order') }}">
                                {!! $icon('grip') !!}
                            </td>

                            <td>
                                <div class="d-flex align-items-center" style="padding-left:{{ $row['depth'] * 26 }}px">
                                    @if ($row['depth'])
                                        <span class="cat-branch" aria-hidden="true"></span>
                                    @endif

                                    @if ($row['image'])
                                        <img src="{{ $row['image'] }}" alt="" class="cat-thumb me-1">
                                    @else
                                        <span class="cat-thumb cat-thumb--empty me-1">
                                            {!! $icon($row['depth'] ? 'child' : 'folder') !!}
                                        </span>
                                    @endif

                                    <div class="min-w-0">
                                        <span @class(['fw-bolder', 'text-muted' => ! $row['active']])>
                                            {{ $row['name'] ?: '—' }}
                                        </span>

                                        @if ($row['system'])
                                            <span class="badge badge-light-secondary ms-25">{{ __('admin.system') }}</span>
                                        @endif

                                        @if ($row['children'])
                                            <small class="d-block text-muted">
                                                {{ $row['children'] }} {{ __('admin.categories') }}
                                            </small>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="text-center">
                                @if ($row['products'])
                                    <a class="badge badge-light-primary text-decoration-none"
                                       href="{{ route('admin.products', ['cat' => $row['id']]) }}">
                                        {{ $row['products'] }}
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-icon btn-flat-secondary"
                                        wire:click="toggleMenu({{ $row['id'] }})"
                                        title="{{ __('admin.in_menu') }}">
                                    {!! $icon($row['menu'] ? 'check' : 'circle', $row['menu'] ? 'text-success' : 'text-muted') !!}
                                </button>
                            </td>

                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-icon btn-flat-secondary"
                                        wire:click="toggleHome({{ $row['id'] }})"
                                        title="{{ __('admin.on_home') }}">
                                    {!! $icon($row['home'] ? 'check' : 'circle', $row['home'] ? 'text-success' : 'text-muted') !!}
                                </button>
                            </td>

                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleActive({{ $row['id'] }})"
                                           @checked($row['active'])
                                           @disabled($row['system'])>
                                </div>
                            </td>

                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                        wire:click="create({{ $row['id'] }})"
                                        title="{{ __('admin.add_child') }}">
                                    {!! $icon('plus') !!}
                                </button>

                                <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                        wire:click="edit({{ $row['id'] }})"
                                        title="{{ __('admin.edit') }}">
                                    {!! $icon('edit') !!}
                                </button>

                                @unless ($row['system'])
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                            wire:click="confirmDelete({{ $row['id'] }})"
                                            title="{{ __('admin.delete') }}">
                                        {!! $icon('trash') !!}
                                    </button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">{{ __('admin.nothing_here') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ------------------------------------------------------------ form --}}
    @if ($showForm)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(34,41,47,.5)">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editingId ? __('admin.edit') : __('admin.create') }}</h5>
                            <button type="button" class="btn-close" wire:click="$set('showForm', false)"></button>
                        </div>

                        <div class="modal-body">
                            <ul class="nav nav-tabs" role="tablist">
                                @foreach ($languages as $i => $language)
                                    <li class="nav-item">
                                        <button type="button" @class(['nav-link', 'active' => $i === 0])
                                                data-bs-toggle="tab" data-bs-target="#lang-{{ $language->code }}">
                                            {{ strtoupper($language->code) }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content pt-1">
                                @foreach ($languages as $i => $language)
                                    <div @class(['tab-pane', 'active' => $i === 0]) id="lang-{{ $language->code }}">
                                        <div class="mb-1">
                                            <label class="form-label">{{ __('admin.name') }}</label>
                                            <input type="text"
                                                   class="form-control @error("translations.{$language->code}.name") is-invalid @enderror"
                                                   wire:model="translations.{{ $language->code }}.name">
                                            @error("translations.{$language->code}.name")
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        <div class="mb-1">
                                            <label class="form-label">{{ __('admin.slug') }}</label>
                                            <input type="text" class="form-control"
                                                   placeholder="{{ __('admin.auto') }}"
                                                   wire:model="translations.{{ $language->code }}.slug">
                                        </div>

                                        <div class="mb-1">
                                            <label class="form-label">{{ __('admin.description') }}</label>
                                            <textarea class="form-control" rows="3"
                                                      wire:model="translations.{{ $language->code }}.description"></textarea>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <hr>

                            <div class="row">
                                <div class="col-md-8 mb-1">
                                    <label class="form-label">{{ __('admin.parent') }}</label>
                                    {{-- any level can be a parent: the storefront draws three --}}
                                    <select class="form-select @error('parent_id') is-invalid @enderror" wire:model="parent_id">
                                        <option value="">— {{ __('admin.root') }} —</option>
                                        @foreach ($options as $option)
                                            @continue ($option['id'] === $editingId)
                                            <option value="{{ $option['id'] }}">
                                                {!! str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) !!}{{ $option['depth'] ? '└ ' : '' }}{{ $option['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('parent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <small class="text-muted">{{ __('admin.order_by_drag') }}</small>
                                </div>

                                <div class="col-md-4 mb-1">
                                    <label class="form-label">{{ __('admin.image') }}</label>
                                    <input type="file" class="form-control" wire:model="image" accept="image/*">
                                    <div wire:loading wire:target="image" class="small text-muted mt-25">{{ __('admin.upload') }}…</div>
                                </div>
                            </div>

                            @if ($currentImage && ! $image)
                                <img src="{{ $currentImage }}" alt="" class="rounded mb-1" width="80" height="80" style="object-fit:cover">
                            @endif

                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="f-active" wire:model="is_active">
                                    <label class="form-check-label" for="f-active">{{ __('admin.active') }}</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="f-home" wire:model="show_on_home">
                                    <label class="form-check-label" for="f-home">{{ __('admin.on_home') }}</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="f-menu" wire:model="show_in_menu">
                                    <label class="form-check-label" for="f-menu">{{ __('admin.in_menu') }}</label>
                                </div>
                            </div>
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

    {{-- ------------------------------------------------------------ delete --}}
    @if ($showDelete)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(34,41,47,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">{{ __('admin.delete') }}: {{ $deletingName }}</h5>
                        <button type="button" class="btn-close" wire:click="$set('showDelete', false)"></button>
                    </div>

                    <div class="modal-body">
                        @if ($deletingChildren || $deletingProducts)
                            <p class="mb-1">{{ __('admin.delete_moves') }}</p>

                            <ul class="list-unstyled mb-1">
                                @if ($deletingChildren)
                                    <li class="d-flex align-items-center gap-1">
                                        <span class="text-warning">{!! $icon('child') !!}</span>
                                        {{ $deletingChildren }} {{ __('admin.categories') }}
                                    </li>
                                @endif
                                @if ($deletingProducts)
                                    <li class="d-flex align-items-center gap-1">
                                        <span class="text-warning">{!! $icon('box') !!}</span>
                                        {{ $deletingProducts }} {{ __('admin.products') }}
                                    </li>
                                @endif
                            </ul>

                            <label class="form-label">{{ __('admin.move_to') }}</label>
                            <select class="form-select @error('moveTo') is-invalid @enderror" wire:model="moveTo">
                                <option value="">— {{ __('admin.uncategorised') }} —</option>
                                @foreach ($options as $option)
                                    @continue ($option['id'] === $deletingId)
                                    <option value="{{ $option['id'] }}">
                                        {!! str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) !!}{{ $option['depth'] ? '└ ' : '' }}{{ $option['name'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('moveTo') <div class="invalid-feedback">{{ $message }}</div> @enderror

                            <small class="text-muted d-block mt-50">{{ __('admin.delete_hint') }}</small>
                        @else
                            <p class="mb-0">{{ __('admin.confirm_delete') }}</p>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('showDelete', false)">
                            {{ __('admin.cancel') }}
                        </button>
                        <button type="button" class="btn btn-danger" wire:click="delete"
                                wire:loading.attr="disabled" wire:target="delete">
                            {{ __('admin.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>