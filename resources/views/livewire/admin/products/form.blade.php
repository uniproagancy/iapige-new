@php
    /* icons are inlined so the controls work whether or not feather loads */
    $icon = function (string $name, string $class = '') {
        $paths = [
            'back'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'save'  => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'eye'   => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'star'  => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'plus'  => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'lock'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };
@endphp

<div>
    {{-- ------------------------------------------------------------ header --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.products') }}">
            {!! $icon('back', 'me-25') !!}{{ __('admin.products') }}
        </a>

        <div class="d-flex align-items-center gap-1">
            @if ($product && $product->status === \App\Models\Product::STATUS_ACTIVE)
                <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
                   href="{{ route('product', $product->slug) }}">
                    {!! $icon('eye', 'me-25') !!}{{ __('admin.view_site') }}
                </a>
            @endif

            @if ($product && $product->status !== \App\Models\Product::STATUS_ACTIVE)
                <button type="button" class="btn btn-success btn-sm" wire:click="saveAndPublish">
                    {!! $icon('eye', 'me-25') !!}{{ __('admin.publish') }}
                </button>
            @endif

            <button type="button" class="btn btn-primary btn-sm" wire:click="save"
                    wire:loading.attr="disabled" wire:target="save">
                {!! $icon('save', 'me-25') !!}{{ __('admin.save') }}
            </button>
        </div>
    </div>

    <div class="row">
        {{-- ------------------------------------------------------------ left --}}
        <div class="col-lg-8">
            {{-- text --}}
            <div class="card">
                <div class="card-body">
                    <ul class="nav nav-tabs" role="tablist">
                        @foreach ($languages as $i => $language)
                            <li class="nav-item">
                                <button type="button" @class(['nav-link', 'active' => $i === 0])
                                        data-bs-toggle="tab" data-bs-target="#t-{{ $language->code }}">
                                    {{ strtoupper($language->code) }}
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content pt-1">
                        @foreach ($languages as $i => $language)
                            <div @class(['tab-pane', 'active' => $i === 0]) id="t-{{ $language->code }}">
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
                                    <input type="text" class="form-control" placeholder="{{ __('admin.auto') }}"
                                           wire:model="translations.{{ $language->code }}.slug">
                                    <small class="text-muted">{{ __('admin.slug_hint') }}</small>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label">{{ __('admin.summary') }}</label>
                                    <textarea class="form-control" rows="2"
                                              wire:model="translations.{{ $language->code }}.summary"></textarea>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label">{{ __('admin.description') }}</label>
                                    <textarea class="form-control" rows="6"
                                              wire:model="translations.{{ $language->code }}.description"></textarea>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- images --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.images') }}</h5>
                </div>
                <div class="card-body">
                    @if (! $product)
                        <p class="text-muted mb-0">{{ __('admin.save_product_first') }}</p>
                    @else
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            @forelse ($images as $image)
                                <div class="position-relative" wire:key="img-{{ $image->id }}">
                                    <img src="{{ $image->url() }}" alt="" class="rounded"
                                         width="92" height="92" style="object-fit:cover">

                                    @if ($loop->first)
                                        <span class="badge badge-light-primary position-absolute top-0 start-0 m-25">
                                            {{ __('admin.main_image') }}
                                        </span>
                                    @else
                                        <button type="button"
                                                class="btn btn-sm btn-icon btn-primary position-absolute top-0 start-0 m-25"
                                                wire:click="makeMain({{ $image->id }})"
                                                title="{{ __('admin.make_main') }}">
                                            {!! $icon('star') !!}
                                        </button>
                                    @endif

                                    <button type="button"
                                            class="btn btn-sm btn-icon btn-danger position-absolute top-0 end-0 m-25"
                                            data-confirm="{{ __('admin.confirm_delete') }}"
                                            data-confirm-action="deleteImage"
                                            data-confirm-arg="{{ $image->id }}">
                                        {!! $icon('trash') !!}
                                    </button>
                                </div>
                            @empty
                                <div class="d-flex align-items-center gap-1 text-muted">
                                    {!! $icon('image') !!} {{ __('admin.no_photo') }}
                                </div>
                            @endforelse
                        </div>

                        <div class="d-flex align-items-center gap-1">
                            <input type="file" class="form-control" multiple accept="image/*" wire:model="upload">
                            <button type="button" class="btn btn-outline-primary text-nowrap"
                                    wire:click="uploadImages" @disabled(! $upload)>
                                {{ __('admin.upload') }}
                            </button>
                        </div>
                        <div wire:loading wire:target="upload" class="small text-muted mt-25">{{ __('admin.upload') }}…</div>
                    @endif
                </div>
            </div>

            {{-- specs --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h5 class="mb-0">{{ __('admin.specs') }}</h5>
                </div>
                <div class="card-body">
                    @if (! $product)
                        <p class="text-muted mb-0">{{ __('admin.save_product_first') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-1">
                                <tbody>
                                    @forelse ($specRows as $spec)
                                        <tr wire:key="spec-{{ $spec->id }}">
                                            <td style="width:35%" class="text-muted">
                                                {{ $spec->attribute?->name ?: $spec->attribute?->code }}
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                       wire:model="specs.{{ $spec->id }}">
                                            </td>
                                            <td style="width:44px" class="text-end">
                                                <button type="button" class="btn btn-sm btn-icon btn-flat-danger"
                                                        wire:click="deleteSpec({{ $spec->id }})">
                                                    {!! $icon('trash') !!}
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted">{{ __('admin.nothing_here') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-1">
                            <div class="col-md-5">
                                <select class="form-select form-select-sm" wire:model="newAttribute">
                                    <option value="">{{ __('admin.attribute') }}…</option>
                                    @foreach ($attributes as $attribute)
                                        <option value="{{ $attribute->id }}">{{ $attribute->name ?: $attribute->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm"
                                       placeholder="{{ __('admin.value') }}" wire:model="newValue">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="addSpec">
                                    {!! $icon('plus') !!}
                                </button>
                            </div>
                        </div>

                        <small class="text-muted d-block mt-1">{{ __('admin.specs_hint') }}</small>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ right --}}
        <div class="col-lg-4">
            {{-- status and taxonomy --}}
            <div class="card">
                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label">{{ __('admin.status') }}</label>
                        <select class="form-select" wire:model="status">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-1">
                        <label class="form-label">{{ __('admin.sku') }}</label>
                        <input type="text" class="form-control @error('sku') is-invalid @enderror" wire:model="sku">
                        @error('sku') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label">{{ __('admin.category') }}</label>
                        <select class="form-select" wire:model="category_id">
                            <option value="">— {{ __('admin.no_category') }} —</option>
                            @foreach ($categories as $option)
                                <option value="{{ $option['id'] }}">
                                    {!! str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) !!}{{ $option['depth'] ? '└ ' : '' }}{{ $option['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-1">
                        <label class="form-label">{{ __('admin.brand') }}</label>
                        <select class="form-select" wire:model="brand_id">
                            <option value="">—</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="lock-tax" wire:model="taxonomy_lock">
                        <label class="form-check-label" for="lock-tax">
                            {!! $icon('lock', 'me-25') !!}{{ __('admin.taxonomy_lock') }}
                        </label>
                    </div>
                </div>
            </div>

            {{-- money --}}
            <div class="card">
                <div class="card-body">
                    <div class="row g-1">
                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.price') }}</label>
                            <input type="number" step="0.01" min="0"
                                   class="form-control @error('price') is-invalid @enderror" wire:model="price">
                            @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.old_price') }}</label>
                            <input type="number" step="0.01" min="0" class="form-control" wire:model="old_price">
                        </div>

                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.cost_price') }}</label>
                            <input type="number" step="0.01" min="0" class="form-control" wire:model="cost_price">
                        </div>

                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.stock') }}</label>
                            <input type="number" min="0" class="form-control" wire:model="stock">
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="lock-price" wire:model="price_lock">
                        <label class="form-check-label" for="lock-price">
                            {!! $icon('lock', 'me-25') !!}{{ __('admin.price_lock') }}
                        </label>
                    </div>
                </div>
            </div>

            {{-- pre-order --}}
            <div class="card">
                <div class="card-body">
                    <div class="form-check form-switch mb-1">
                        <input type="checkbox" class="form-check-input" id="pre" wire:model.live="is_preorder">
                        <label class="form-check-label" for="pre">{{ __('admin.preorder') }}</label>
                    </div>

                    @if ($is_preorder)
                        <label class="form-label">{{ __('admin.release_date') }}</label>
                        <input type="date" class="form-control" wire:model="release_date">
                        <small class="text-muted d-block mt-25">{{ __('admin.preorder_hint') }}</small>
                    @endif
                </div>
            </div>

            {{-- shipping --}}
            <div class="card">
                <div class="card-body">
                    <div class="row g-1">
                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.weight') }}</label>
                            <input type="number" min="0" class="form-control" wire:model="weight">
                        </div>
                        <div class="col-6 mb-1">
                            <label class="form-label">{{ __('admin.dimensions') }}</label>
                            <div class="d-flex gap-25">
                                <input type="number" min="0" class="form-control" placeholder="L" wire:model="length">
                                <input type="number" min="0" class="form-control" placeholder="W" wire:model="width">
                                <input type="number" min="0" class="form-control" placeholder="H" wire:model="height">
                            </div>
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="bulky" wire:model="is_bulky">
                        <label class="form-check-label" for="bulky">{{ __('admin.is_bulky') }}</label>
                    </div>
                </div>
            </div>

            {{-- what the suppliers offer, for reference only --}}
            @if ($offers->isNotEmpty())
                <div class="card">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">{{ __('admin.suppliers') }}</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tbody>
                                @foreach ($offers as $offer)
                                    <tr wire:key="offer-{{ $offer->id }}">
                                        <td>{{ $offer->supplier?->name }}</td>
                                        <td class="text-muted">{{ $offer->external_id }}</td>
                                        <td class="text-end">{{ money($offer->cost_price) }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-light-secondary">{{ $offer->stock }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <small class="text-muted d-block mt-1">{{ __('admin.offers_hint') }}</small>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
