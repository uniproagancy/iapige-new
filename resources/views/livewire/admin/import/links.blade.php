<div>
    <div class="row">
        {{-- ------------------------------------------------------------ the box --}}
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.links_title') }}</h4>
                    <small class="text-muted">{{ __('admin.links_hint') }}</small>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="l-supplier">{{ __('admin.supplier') }}</label>
                            <select id="l-supplier" class="form-select @error('supplierCode') is-invalid @enderror"
                                    wire:model.live="supplierCode">
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->code }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('supplierCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="l-category">{{ __('admin.links_category') }}</label>
                            <input id="l-category" type="text" class="form-control"
                                   wire:model="category"
                                   placeholder="{{ __('admin.links_category_hint') }}">
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="l-links">{{ __('admin.links_label') }}</label>
                        <textarea id="l-links" rows="10"
                                  class="form-control font-monospace @error('links') is-invalid @enderror"
                                  wire:model="links"
                                  placeholder="https://citrus.ge/product/gamtsovi-marazzi-msbhd-60fs370wg&#10;https://citrus.ge/product/..."></textarea>
                        @error('links') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('admin.links_separators') }}</small>
                    </div>

                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="save">{{ __('admin.links_save') }}</span>
                            <span wire:loading wire:target="save">{{ __('admin.links_save') }}…</span>
                        </button>

                        @if ($total)
                            <button type="button" class="btn btn-outline-success ms-auto"
                                    wire:click="run" wire:loading.attr="disabled">
                                {{ __('admin.links_run', ['count' => $total]) }}
                            </button>
                        @endif
                    </div>

                    {{-- ------------------------------------------------ what happened --}}
                    @if ($result)
                        <hr>

                        <ul class="list-unstyled mb-0">
                            <li class="text-success">{{ __('admin.links_added', ['count' => $result['added']]) }}</li>
                            <li class="text-muted">{{ __('admin.links_updated', ['count' => $result['updated']]) }}</li>

                            @if (count($result['skipped']))
                                <li class="text-danger mt-1">
                                    {{ __('admin.links_skipped', ['count' => count($result['skipped'])]) }}
                                </li>
                                <li>
                                    <pre class="small text-muted mb-0">{{ implode("\n", array_slice($result['skipped'], 0, 10)) }}</pre>
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ what is waiting --}}
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.links_waiting', ['count' => $total]) }}</h4>
                </div>

                <div class="card-body">
                    @forelse ($rows as $row)
                        <div class="d-flex justify-content-between align-items-center py-50 border-bottom"
                             wire:key="row-{{ $row->id }}">
                            <span class="text-truncate me-1">
                                {{ $row->external_id }}
                                @if ($row->data['category'] ?? null)
                                    <small class="d-block text-muted">{{ $row->data['category'] }}</small>
                                @endif
                            </span>

                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    wire:click="forget('{{ $row->external_id }}')"
                                    wire:confirm="{{ __('admin.confirm_delete') }}">×</button>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ __('admin.links_empty') }}</p>
                    @endforelse

                    @if ($total > $rows->count())
                        <p class="text-muted small mt-1 mb-0">
                            {{ __('admin.links_more', ['count' => $total - $rows->count()]) }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
