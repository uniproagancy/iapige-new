<div>
    <div class="row">

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.json_title') }}</h4>
                    <small class="text-muted">{{ __('admin.json_hint') }}</small>
                </div>

                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label" for="j-supplier">{{ __('admin.supplier') }}</label>
                        <select id="j-supplier" class="form-select @error('code') is-invalid @enderror"
                                wire:model.live="code">
                            @foreach ($suppliers as $row)
                                <option value="{{ $row->code }}">{{ $row->name }}</option>
                            @endforeach
                        </select>
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1" x-data="{ uploading: false }"
                         x-on:livewire-upload-start="uploading = true"
                         x-on:livewire-upload-finish="uploading = false"
                         x-on:livewire-upload-error="uploading = false"
                         x-on:livewire-upload-cancel="uploading = false">
                        <label class="form-label" for="j-file">{{ __('admin.json_file_label') }}</label>
                        <input id="j-file" type="file" accept=".json,application/json"
                               class="form-control @error('upload') is-invalid @enderror" wire:model="upload"
                               wire:loading.attr="disabled" wire:target="look,save,clearUpload">
                        @error('upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted d-block">{{ __('admin.json_file_hint', ['mb' => 10, 'count' => \App\Livewire\Admin\Import\Json::MAX_ANSWERS]) }}</small>
                        <small class="text-muted" x-cloak x-show="uploading">{{ __('admin.json_uploading') }}</small>
                        @if ($upload)
                            <div class="d-flex align-items-center gap-1 mt-50">
                                <span class="small text-break">{{ $upload->getClientOriginalName() }}</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearUpload"
                                        wire:loading.attr="disabled">{{ __('admin.json_file_remove') }}</button>
                            </div>
                        @endif
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="j-json">{{ __('admin.json_label') }}</label>
                        <textarea id="j-json" rows="12"
                                  class="form-control font-monospace small @error('json') is-invalid @enderror"
                                  wire:model.blur="json"
                                  @disabled($upload !== null)
                                  placeholder='{"product":{"id":45491,"barCode":"169226", …}}'></textarea>
                        @error('json') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('admin.json_separators') }}</small>
                    </div>

                    <button type="button" class="btn btn-outline-primary"
                            wire:click="look" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="look">{{ __('admin.json_look') }}</span>
                        <span wire:loading wire:target="look">{{ __('admin.json_look') }}…</span>
                    </button>

                    @if (count($problems))
                        <ul class="list-unstyled mt-1 mb-0">
                            @foreach ($problems as $message)
                                <li class="text-danger small">{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @if (count($result))
                <div class="card {{ $photoWarnings ? 'border-warning' : 'border-success' }}">
                    <div class="card-header pb-50">
                        <h5 class="card-title mb-0 {{ $photoWarnings ? 'text-warning' : 'text-success' }}">
                            {{ __($photoWarnings ? 'admin.json_saved_with_photo_warnings' : 'admin.json_saved_many', ['count' => count($result)]) }}
                        </h5>
                    </div>

                    <div class="card-body">
                        @foreach ($result as $saved)
                            <div class="d-flex justify-content-between align-items-center py-50 border-bottom"
                                 wire:key="saved-{{ $saved['id'] }}">
                                <span class="text-truncate me-1">
                                    <span class="font-monospace small">{{ $saved['sku'] }}</span>
                                    <span class="d-block text-truncate">{{ $saved['name'] }}</span>
                                    <small class="text-muted">
                                        {{ money($saved['price']) }} ·
                                        {{ __('admin.json_stock', ['count' => $saved['stock']]) }} ·
                                        {{ __('admin.json_images', ['count' => $saved['images']]) }}
                                    </small>
                                    @if (! ($saved['photos_complete'] ?? true))
                                        <small class="text-warning d-block">{{ __('admin.json_photos_warning') }}</small>
                                    @endif
                                </span>

                                <span class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $saved['edit'] }}" wire:navigate>
                                        {{ __('admin.edit') }}
                                    </a>

                                    @if ($saved['live'])
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ $saved['live'] }}"
                                           target="_blank" rel="noopener">{{ __('admin.json_on_shop') }}</a>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-6">
            @if (count($preview))
                <div class="card">
                    <div class="card-header pb-50">
                        <h4 class="card-title mb-0">
                            {{ __('admin.json_preview') }}
                            <span class="badge rounded-pill badge-light-primary ms-50">{{ count($preview) }}</span>
                        </h4>
                    </div>

                    <div class="card-body">
                        @foreach ($preview as $row)
                            <div class="d-flex py-50 border-bottom" wire:key="seen-{{ $row['id'] }}">
                                @if ($row['images'])
                                    <img src="{{ $row['images'][0] }}" alt=""
                                         class="rounded me-1 flex-shrink-0"
                                         style="width:52px;height:52px;object-fit:contain">
                                @endif

                                <div class="flex-grow-1 text-truncate">
                                    <span class="d-block text-truncate">{{ $row['name'] }}</span>

                                    <small class="text-muted d-block">
                                        <span class="font-monospace">{{ $row['code'] }}</span> ·
                                        {{ $row['brand'] ?? '—' }} ·
                                        {{ $row['category'] ?? '—' }}
                                    </small>

                                    <small class="d-block">
                                        {{ money($row['price']) }}
                                        @if ($row['previous'] > $row['price'])
                                            <s class="text-muted ms-25">{{ money($row['previous']) }}</s>
                                        @endif
                                        <span class="text-muted">
                                            · {{ __('admin.json_images', ['count' => count($row['images'])]) }}
                                            · {{ $row['specs_count'] }} {{ __('admin.json_specs') }}
                                            · {{ $row['branches_in_stock'] }}/{{ $row['branches'] }}
                                        </span>
                                    </small>

                                    @if ($already->has($supplier->code.'-'.$row['code']))
                                        <small class="text-warning d-block">{{ __('admin.json_have_it') }}</small>
                                    @endif

                                    @if (! $row['ok'])
                                        <small class="text-danger d-block">{{ __('admin.json_skipped') }}</small>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        @if ($this->alone())
                            <div class="row mt-1">
                                <div class="col-6 mb-1">
                                    <label class="form-label" for="j-price">{{ __('admin.json_cost') }}</label>
                                    <input id="j-price" type="number" step="0.01" min="0"
                                           class="form-control @error('price') is-invalid @enderror"
                                           wire:model.live.debounce.400ms="price">
                                    @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-6 mb-1">
                                    <label class="form-label" for="j-stock">{{ __('admin.json_our_stock') }}</label>
                                    <input id="j-stock" type="number" min="0"
                                           class="form-control @error('stock') is-invalid @enderror"
                                           wire:model.live.debounce.400ms="stock">
                                    @error('stock') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            @if ($selling !== null)
                                <p class="small mb-1">
                                    {{ __('admin.json_selling', ['price' => money($selling)]) }}
                                </p>
                            @endif
                        @else
                            <p class="small text-muted mt-1 mb-1">{{ __('admin.json_many_hint') }}</p>
                        @endif

                        <button type="button" class="btn btn-success w-100 mt-1"
                                wire:click="save" wire:loading.attr="disabled" @disabled($previewFingerprint === null)>
                            <span wire:loading.remove wire:target="save">
                                {{ __('admin.json_save_many', ['count' => count($preview)]) }}
                            </span>
                            <span wire:loading wire:target="save">
                                {{ __('admin.json_save_many', ['count' => count($preview)]) }}…
                            </span>
                        </button>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-0">{{ __('admin.json_empty') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
