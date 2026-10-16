<div>
    <div class="row">

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
                                    wire:model.live="supplierCode" @disabled($running)>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->code }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('supplierCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="l-category">{{ __('admin.links_category') }}</label>
                            <input id="l-category" type="text" class="form-control"
                                   wire:model="category" @disabled($running)
                                   placeholder="{{ __('admin.links_category_hint') }}">
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="l-links">{{ __('admin.links_label') }}</label>
                        <textarea id="l-links" rows="8"
                                  class="form-control font-monospace @error('links') is-invalid @enderror"
                                  wire:model="links" @disabled($running)
                                  placeholder="https://citrus.ge/product/gamtsovi-marazzi-msbhd-60fs370wg&#10;https://citrus.ge/product/..."></textarea>
                        @error('links') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('admin.links_separators') }}</small>
                    </div>

                    <button type="button" class="btn btn-outline-primary"
                            wire:click="save" wire:loading.attr="disabled" @disabled($running)>
                        <span wire:loading.remove wire:target="save">{{ __('admin.links_save') }}</span>
                        <span wire:loading wire:target="save">{{ __('admin.links_save') }}…</span>
                    </button>

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

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.links_fetch_title') }}</h4>
                    <small class="text-muted">{{ __('admin.links_fetch_hint') }}</small>
                </div>

                <div class="card-body">
                    @if ($running)
                        @php $percent = $total > 0 ? (int) round(($done + $failed) / $total * 100) : 0; @endphp

                        <div wire:poll.600ms="fetchNext">
                            <div class="d-flex justify-content-between mb-50">
                                <span>{{ __('admin.links_fetching', ['done' => $done + $failed, 'total' => $total]) }}</span>
                                <span class="text-muted">{{ $percent }}%</span>
                            </div>

                            <div class="progress mb-1" style="height:8px">
                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                     style="width: {{ $percent }}%"></div>
                            </div>

                            @if ($current)
                                <p class="small text-muted text-truncate mb-1">{{ $current }}</p>
                            @endif

                            <div class="d-flex gap-1 align-items-center">
                                <span class="badge rounded-pill badge-light-success">{{ __('admin.links_done', ['count' => $done]) }}</span>

                                @if ($failed)
                                    <span class="badge rounded-pill badge-light-danger">{{ __('admin.links_failed', ['count' => $failed]) }}</span>
                                @endif

                                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                                        wire:click="stop">{{ __('admin.links_stop') }}</button>
                            </div>
                        </div>
                    @else
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <button type="button" class="btn btn-success"
                                    wire:click="start" @disabled(! $waiting)>
                                {{ __('admin.links_fetch', ['count' => $waiting]) }}
                            </button>

                            @if ($count)
                                <button type="button" class="btn btn-outline-secondary"
                                        wire:click="start(true)">
                                    {{ __('admin.links_refresh', ['count' => $count]) }}
                                </button>
                            @endif
                        </div>

                        @if ($done || $failed)
                            <p class="mt-1 mb-0">
                                <span class="text-success">{{ __('admin.links_done', ['count' => $done]) }}</span>
                                @if ($failed)
                                    · <span class="text-danger">{{ __('admin.links_failed', ['count' => $failed]) }}</span>
                                @endif
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('admin.links_waiting', ['count' => $count]) }}</h4>
                </div>

                <div class="card-body">
                    @forelse ($rows as $row)
                        <div class="d-flex justify-content-between align-items-center py-50 border-bottom"
                             wire:key="row-{{ $row->id }}">
                            <span class="text-truncate me-1">
                                @if ($held->has($row->external_id))
                                    <a href="{{ route('admin.products.edit', $held[$row->external_id]) }}"
                                       class="text-success" wire:navigate>{{ $row->external_id }}</a>
                                @else
                                    {{ $row->external_id }}
                                @endif

                                @if ($row->data['category'] ?? null)
                                    <small class="d-block text-muted">{{ $row->data['category'] }}</small>
                                @endif
                            </span>

                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    wire:click="forget('{{ $row->external_id }}')"
                                    wire:confirm="{{ __('admin.confirm_delete') }}"
                                    @disabled($running)>×</button>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ __('admin.links_empty') }}</p>
                    @endforelse

                    @if ($count > $rows->count())
                        <p class="text-muted small mt-1 mb-0">
                            {{ __('admin.links_more', ['count' => $count - $rows->count()]) }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
