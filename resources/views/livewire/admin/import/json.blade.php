<div>
    <div class="row">

        <div class="col-lg-7">
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

                    <div class="mb-1">
                        <label class="form-label" for="j-json">{{ __('admin.json_label') }}</label>
                        <textarea id="j-json" rows="10"
                                  class="form-control font-monospace small @error('json') is-invalid @enderror"
                                  wire:model.blur="json"
                                  placeholder='{"product":{"id":45491,"barCode":"169226", …}}'></textarea>
                        @error('json') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('admin.json_separators') }}</small>
                    </div>

                    <button type="button" class="btn btn-outline-primary"
                            wire:click="look" wire:loading.attr="disabled" wire:target="look">
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

            @if ($result)
                <div class="card border-success">
                    <div class="card-body">
                        <h5 class="mb-50 text-success">{{ __('admin.json_saved_title') }}</h5>

                        <p class="mb-50">
                            <span class="font-monospace">{{ $result['sku'] }}</span> — {{ $result['name'] }}
                        </p>

                        <p class="small text-muted mb-1">
                            {{ money($result['price']) }} ·
                            {{ __('admin.json_stock', ['count' => $result['stock']]) }} ·
                            {{ __('admin.json_images', ['count' => $result['images']]) }}
                        </p>

                        <a class="btn btn-sm btn-primary" href="{{ $result['edit'] }}" wire:navigate>
                            {{ __('admin.edit') }}
                        </a>

                        @if ($result['live'])
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $result['live'] }}"
                               target="_blank" rel="noopener">{{ __('admin.json_on_shop') }}</a>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            @if ($preview)
                <div class="card">
                    <div class="card-header pb-50">
                        <h4 class="card-title mb-0">{{ __('admin.json_preview') }}</h4>
                    </div>

                    <div class="card-body">
                        @if ($preview['images'])
                            <img src="{{ $preview['images'][0] }}" alt=""
                                 class="rounded mb-1" style="max-width:120px;max-height:120px;object-fit:contain">
                        @endif

                        <h5 class="mb-50">{{ $preview['name'] }}</h5>

                        @if ($already)
                            <div class="alert alert-warning py-50 px-1 small mb-1">
                                {{ __('admin.json_exists', ['sku' => $already->sku]) }}
                                @if ($already->trashed())
                                    <span class="d-block">{{ __('admin.json_exists_archived') }}</span>
                                @endif
                            </div>
                        @endif

                        <table class="table table-sm mb-1">
                            <tbody>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_code') }}</td>
                                    <td class="font-monospace">{{ $preview['code'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_site_id') }}</td>
                                    <td>
                                        @if ($preview['url'])
                                            <a href="{{ $preview['url'] }}" target="_blank" rel="noopener">{{ $preview['id'] }}</a>
                                        @else
                                            {{ $preview['id'] }}
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.brand') }}</td>
                                    <td>{{ $preview['brand'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.category') }}</td>
                                    <td>
                                        {{ $preview['category'] ?? '—' }}
                                        @if ($preview['parent_category'])
                                            <small class="d-block text-muted">{{ $preview['parent_category'] }}</small>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_on_site') }}</td>
                                    <td>
                                        {{ money($preview['price']) }}
                                        @if ($preview['previous'] > $preview['price'])
                                            <s class="text-muted ms-25">{{ money($preview['previous']) }}</s>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_branches') }}</td>
                                    <td>{{ $preview['branches_in_stock'] }} / {{ $preview['branches'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_specs') }}</td>
                                    <td>
                                        {{ $preview['specs_count'] }}
                                        @if ($preview['weight'])
                                            <small class="d-block text-muted">{{ $preview['weight'] }} g</small>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('admin.json_photos') }}</td>
                                    <td>{{ count($preview['images']) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="row">
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

                        <button type="button" class="btn btn-success w-100"
                                wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ __('admin.json_save') }}</span>
                            <span wire:loading wire:target="save">{{ __('admin.json_save') }}…</span>
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
