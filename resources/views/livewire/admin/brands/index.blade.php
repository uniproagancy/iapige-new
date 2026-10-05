@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'   => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'star'   => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'tag'    => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    <div class="card">
        <div class="card-body pb-1 d-flex flex-wrap align-items-center justify-content-between gap-1">
            <div class="input-group input-group-merge" style="max-width:320px">
                <span class="input-group-text">{!! $icon('search') !!}</span>
                <input type="text" class="form-control" placeholder="{{ __('admin.brand') }}"
                       wire:model.live.debounce.400ms="search">
            </div>

            <div class="d-flex align-items-center gap-1">
                {{-- a brand nobody sells is noise in the sidebar --}}
                <button type="button" @class(['btn btn-sm', 'btn-warning' => $onlyEmpty, 'btn-outline-warning' => ! $onlyEmpty])
                        wire:click="$toggle('onlyEmpty')">
                    {{ __('admin.brands_empty') }}
                </button>

                <button type="button" class="btn btn-sm btn-primary" wire:click="create">
                    {!! $icon('plus', 'me-25') !!}{{ __('admin.create') }}
                </button>
            </div>
        </div>
    </div>

    @if ($showForm)
        <div class="card border-primary">
            <div class="card-body">
                <div class="row g-1">
                    <div class="col-md-4">
                        <label class="form-label">{{ __('admin.name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model="name">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('admin.slug') }}</label>
                        <input type="text" class="form-control" placeholder="{{ __('admin.auto') }}" wire:model="slug">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('admin.logo') }}</label>
                        <input type="file" class="form-control" accept="image/*" wire:model="logo">
                        <div wire:loading wire:target="logo" class="small text-muted">{{ __('admin.upload') }}…</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-1 mt-1">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="b-active" wire:model="is_active">
                        <label class="form-check-label" for="b-active">{{ __('admin.status_active') }}</label>
                    </div>

                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="b-featured" wire:model="is_featured">
                        <label class="form-check-label" for="b-featured">{{ __('admin.featured') }}</label>
                    </div>

                    <div class="ms-auto d-flex gap-1">
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('showForm', false)">
                            {{ __('admin.cancel') }}
                        </button>
                        <button type="button" class="btn btn-primary" wire:click="save">{{ __('admin.save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:60px"></th>
                        <th>{{ __('admin.name') }}</th>
                        <th class="text-center" style="width:120px">{{ __('admin.products') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.featured') }}</th>
                        <th class="text-end" style="width:120px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($brands as $brand)
                        <tr wire:key="b-{{ $brand->id }}">
                            <td>
                                @if ($brand->logoUrl())
                                    <img src="{{ $brand->logoUrl() }}" alt="" height="28" style="max-width:52px;object-fit:contain">
                                @else
                                    <span class="text-muted">{!! $icon('tag') !!}</span>
                                @endif
                            </td>

                            <td>
                                <span class="fw-bolder d-block">{{ $brand->name }}</span>
                                <small class="text-muted">{{ $brand->slug }}</small>
                            </td>

                            <td class="text-center">
                                @if ($brand->products_count)
                                    <a href="{{ route('admin.products') }}?brand={{ $brand->id }}">
                                        <span class="badge badge-light-secondary">{{ $brand->products_count }}</span>
                                    </a>
                                @else
                                    <span class="badge badge-light-warning">0</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <button type="button"
                                        @class(['btn btn-sm btn-icon', 'btn-primary' => $brand->is_featured, 'btn-outline-secondary' => ! $brand->is_featured])
                                        wire:click="toggleFeatured({{ $brand->id }})"
                                        title="{{ __('admin.featured') }}">
                                    {!! $icon('star') !!}
                                </button>
                            </td>

                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                        wire:click="edit({{ $brand->id }})">
                                    {!! $icon('edit') !!}
                                </button>

                                {{-- a brand with products behind it would orphan them --}}
                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                        data-confirm="{{ __('admin.confirm_delete') }}"
                                        data-confirm-action="delete"
                                        data-confirm-arg="{{ $brand->id }}"
                                        @disabled($brand->products_count > 0)>
                                    {!! $icon('trash') !!}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('tag') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($brands->hasPages())
            <div class="card-body pt-1">{{ $brands->links() }}</div>
        @endif
    </div>
</div>
