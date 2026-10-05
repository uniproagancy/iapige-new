@php
    /* icons are inlined so the controls work whether or not feather loads */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'search'  => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'x'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'plus'    => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'    => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash'   => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'eye'     => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off' => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
            'check'   => '<polyline points="20 6 9 17 4 12"/>',
            'box'     => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/>',
            'image'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'archive' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
            'external'=> '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
            'lock'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'unlock'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
            'award'   => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    {{-- ------------------------------------------------------------ what needs work
         "ready" leads and is the only green one: that pile wants a click, not a fix --}}
    <div class="d-flex flex-wrap gap-1 mb-2">
        @foreach ([
            'ready'    => ['label' => __('admin.ready_to_publish'), 'tone' => 'success'],
            'nocat'    => ['label' => __('admin.no_category'), 'tone' => 'danger'],
            'nobrand'  => ['label' => __('admin.no_brand'),    'tone' => 'warning'],
            'nophoto'  => ['label' => __('admin.no_photo'),    'tone' => 'warning'],
            'noprice'  => ['label' => __('admin.no_price'),    'tone' => 'danger'],
            'out'      => ['label' => __('admin.out_of_stock'),'tone' => 'secondary'],
            'noweight' => ['label' => __('admin.no_weight'),   'tone' => 'secondary'],
        ] as $key => $meta)
            @continue (! ($issues[$key] ?? 0))
            <button type="button"
                    @class(['btn btn-sm', 'btn-'.$meta['tone'] => $issue === $key, 'btn-outline-'.$meta['tone'] => $issue !== $key])
                    wire:click="$set('issue', '{{ $issue === $key ? '' : $key }}')">
                {{ $meta['label'] }}
                <span class="badge bg-white text-dark ms-50">{{ $issues[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ filters --}}
    <div class="card">
        <div class="card-body pb-1">
            <div class="row g-1">
                <div class="col-lg-4 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text">{!! $icon('search') !!}</span>
                        <input type="text" class="form-control"
                               placeholder="{{ __('admin.search_products') }}"
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="col-lg-2 col-md-6">
                    <select class="form-select" wire:model.live="status">
                        <option value="">{{ __('admin.any_state') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select class="form-select" wire:model.live="categoryId">
                        <option value="">{{ __('admin.all_categories') }}</option>
                        @foreach ($categories as $option)
                            <option value="{{ $option['id'] }}">
                                {!! str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) !!}{{ $option['depth'] ? '└ ' : '' }}{{ $option['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="row g-1">
                        <div class="col-6">
                            <select class="form-select" wire:model.live="brandId">
                                <option value="">{{ __('admin.all_brands') }}</option>
                                @foreach ($brands as $brand)
                                    <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <select class="form-select" wire:model.live="supplierId">
                                <option value="">{{ __('admin.all_suppliers') }}</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mt-1 pb-1">
                <small class="text-muted d-flex align-items-center gap-1">
                    {{ __('admin.found_n', ['count' => $products->total()]) }}

                    @if ($this->hasFilters())
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="resetFilters">
                            {!! $icon('x', 'me-25') !!}{{ __('admin.reset') }}
                        </button>
                    @endif
                </small>

                <div class="d-flex align-items-center gap-1">
                    <select class="form-select form-select-sm" style="width:auto" wire:model.live="sort">
                        <option value="new">{{ __('admin.sort_new') }}</option>
                        <option value="old">{{ __('admin.sort_old') }}</option>
                        <option value="price-asc">{{ __('admin.sort_price_asc') }}</option>
                        <option value="price-desc">{{ __('admin.sort_price_desc') }}</option>
                        <option value="stock">{{ __('admin.sort_stock') }}</option>
                        <option value="sales">{{ __('admin.sort_sales') }}</option>
                    </select>

                    <select class="form-select form-select-sm" style="width:auto" wire:model.live="perPage">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>

                    <a class="btn btn-primary btn-sm" href="{{ route('admin.products.create') }}">
                        {!! $icon('plus', 'me-25') !!}{{ __('admin.create') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ bulk bar --}}
    @if ($selected)
        <div class="card border-primary">
            <div class="card-body py-1">
                <div class="d-flex flex-wrap align-items-center gap-1">
                    <span class="fw-bolder me-1">
                        {{ __('admin.n_selected', ['count' => count($selected)]) }}
                    </span>

                    @if (! $selectPage || count($selected) < $products->total())
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="selectAllFiltered">
                            {{ __('admin.select_all_filtered', ['count' => $products->total()]) }}
                        </button>
                    @endif

                    <button type="button" class="btn btn-sm btn-success" wire:click="bulkPublish">
                        {!! $icon('check', 'me-25') !!}{{ __('admin.publish') }}
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="bulkUnpublish">
                        {!! $icon('eye-off', 'me-25') !!}{{ __('admin.unpublish') }}
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="bulkArchive">
                        {!! $icon('archive', 'me-25') !!}{{ __('admin.archive') }}
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto"
                            data-confirm="{{ __('admin.bulk_delete_confirm') }}"
                            data-confirm-action="bulkDelete">
                        {!! $icon('trash', 'me-25') !!}{{ __('admin.delete') }}
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearSelection">
                        {!! $icon('x') !!}
                    </button>
                </div>

                {{-- moving products is a separate row: it needs two pickers and a lock --}}
                <div class="d-flex flex-wrap align-items-center gap-1 mt-1 pt-1 border-top">
                    <div class="d-flex align-items-center gap-1">
                        <select class="form-select form-select-sm" style="width:230px" wire:model="bulkCategory">
                            <option value="">{{ __('admin.move_to') }}…</option>
                            @foreach ($categories as $option)
                                <option value="{{ $option['id'] }}">
                                    {!! str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) !!}{{ $option['depth'] ? '└ ' : '' }}{{ $option['name'] }}
                                </option>
                            @endforeach
                        </select>

                        <button type="button" class="btn btn-sm btn-primary" wire:click="bulkSetCategory">
                            {{ __('admin.apply') }}
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-1">
                        <select class="form-select form-select-sm" style="width:180px" wire:model="bulkBrand">
                            <option value="">{{ __('admin.brand') }}…</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>

                        <button type="button" class="btn btn-sm btn-primary"
                                wire:click="bulkSetBrand" @disabled(! $bulkBrand)>
                            {{ __('admin.apply') }}
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-1 ms-auto">
                        {{-- without the lock the next import puts the supplier's own values back --}}
                        <button type="button" class="btn btn-sm btn-outline-warning"
                                wire:click="bulkLockTaxonomy(true)">
                            {!! $icon('lock', 'me-25') !!}{{ __('admin.lock') }}
                        </button>

                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="bulkLockTaxonomy(false)">
                            {!! $icon('unlock', 'me-25') !!}{{ __('admin.unlock') }}
                        </button>
                    </div>
                </div>

                <small class="text-muted d-block mt-50">{{ __('admin.bulk_lock_hint') }}</small>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ list --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:40px">
                            <input type="checkbox" class="form-check-input" wire:model.live="selectPage">
                        </th>
                        <th style="width:56px"></th>
                        <th>{{ __('admin.name') }}</th>
                        <th style="width:180px">{{ __('admin.category') }}</th>
                        <th class="text-end" style="width:130px">{{ __('admin.price') }}</th>
                        <th class="text-center" style="width:90px">{{ __('admin.stock') }}</th>
                        <th class="text-center" style="width:110px">{{ __('admin.status') }}</th>
                        <th class="text-end" style="width:140px">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php
                            $image = $product->images->first()?->url();
                            $problems = collect([
                                ! $product->category_id ? __('admin.no_category') : null,
                                ! $product->brand_id ? __('admin.no_brand') : null,
                                (float) $product->price <= 0 ? __('admin.no_price') : null,
                                ! $image ? __('admin.no_photo') : null,
                            ])->filter();
                        @endphp

                        <tr wire:key="p-{{ $product->id }}" @class(['table-active' => in_array($product->id, $selected, true)])>
                            <td>
                                <input type="checkbox" class="form-check-input"
                                       value="{{ $product->id }}" wire:model.live="selected">
                            </td>

                            <td>
                                @if ($image)
                                    <img src="{{ $image }}" alt="" class="rounded" width="40" height="40" style="object-fit:cover">
                                @else
                                    <span class="d-inline-flex align-items-center justify-content-center rounded text-muted"
                                          style="width:40px;height:40px;background:rgba(255,255,255,.05)">
                                        {!! $icon('image') !!}
                                    </span>
                                @endif
                            </td>

                            <td>
                                <a class="fw-bolder d-block text-body" href="{{ route('admin.products.edit', $product) }}">
                                    {{ $product->name ?: '—' }}
                                    @if ($product->taxonomy_lock)
                                        <span class="text-warning ms-25" title="{{ __('admin.taxonomy_lock') }}">{!! $icon('lock') !!}</span>
                                    @endif
                                </a>

                                <small class="text-muted">
                                    {{ $product->sku }}
                                    @if ($product->brand) · {{ $product->brand->name }} @endif
                                    @foreach ($product->offers as $offer)
                                        <span class="badge bg-light-secondary ms-25">{{ $offer->supplier?->name }}</span>
                                    @endforeach
                                </small>

                                @if ($problems->isNotEmpty())
                                    <div class="mt-25">
                                        @foreach ($problems as $problem)
                                            <span class="badge badge-light-danger me-25">{{ $problem }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            <td class="text-muted">{{ $product->category?->name ?? '—' }}</td>

                            <td class="text-end">
                                <span class="fw-bolder">{{ money($product->price) }}</span>
                                @if ($product->old_price)
                                    <small class="d-block text-muted text-decoration-line-through">{{ money($product->old_price) }}</small>
                                @endif
                            </td>

                            <td class="text-center">
                                @if ($product->is_preorder && $product->stock < 1)
                                    <span class="badge badge-light-info">{{ __('admin.preorder') }}</span>
                                @elseif ($product->stock > 0)
                                    <span class="badge badge-light-success">{{ $product->stock }}</span>
                                @else
                                    <span class="badge badge-light-secondary">0</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <span @class([
                                    'badge',
                                    'badge-light-success'   => $product->status === \App\Models\Product::STATUS_ACTIVE,
                                    'badge-light-warning'   => $product->status === \App\Models\Product::STATUS_DRAFT,
                                    'badge-light-secondary' => $product->status === \App\Models\Product::STATUS_ARCHIVED,
                                ])>{{ $statuses[$product->status] ?? $product->status }}</span>
                            </td>

                            <td class="text-end text-nowrap">
                                @if ($product->status === \App\Models\Product::STATUS_ACTIVE)
                                    <a class="btn btn-sm btn-icon btn-outline-secondary"
                                       href="{{ route('product', $product->slug) }}" target="_blank" rel="noopener"
                                       title="{{ __('admin.view_site') }}">
                                        {!! $icon('external') !!}
                                    </a>

                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                            wire:click="unpublish({{ $product->id }})"
                                            title="{{ __('admin.unpublish') }}">
                                        {!! $icon('eye-off') !!}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-success"
                                            wire:click="publish({{ $product->id }})"
                                            title="{{ __('admin.publish') }}">
                                        {!! $icon('eye') !!}
                                    </button>
                                @endif

                                <a class="btn btn-sm btn-icon btn-outline-primary"
                                   href="{{ route('admin.products.edit', $product) }}"
                                   title="{{ __('admin.edit') }}">
                                    {!! $icon('edit') !!}
                                </a>

                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                        data-confirm="{{ __('admin.confirm_delete') }}"
                                        data-confirm-action="delete"
                                        data-confirm-arg="{{ $product->id }}"
                                        title="{{ __('admin.delete') }}">
                                    {!! $icon('trash') !!}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <span class="d-block mb-25">{!! $icon('box') !!}</span>
                                {{ __('admin.nothing_here') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="card-body pt-1">{{ $products->links() }}</div>
        @endif
    </div>
</div>
