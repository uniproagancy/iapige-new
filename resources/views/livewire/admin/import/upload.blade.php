<div>
    @if ($suppliers->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="mb-0 text-muted">{{ __('admin.import_no_file_suppliers') }}</p>
            </div>
        </div>
    @else

        <div class="card">
            <div class="card-body d-flex flex-wrap align-items-center gap-1">
                @foreach ($suppliers as $s)
                    <button type="button"
                            @class([
                                'btn btn-sm',
                                'btn-primary' => $s->code === $code,
                                'btn-outline-secondary' => $s->code !== $code,
                            ])
                            wire:click="$set('code', '{{ $s->code }}')">
                        {{ $s->name }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($supplier)

            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="fw-bolder mb-0">{{ number_format($loaded) }}</h2>
                            <span class="text-muted">{{ __('admin.import_rows_in_stock_table') }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="fw-bolder mb-0 text-success">{{ number_format($inStock) }}</h2>
                            <span class="text-muted">{{ __('admin.import_rows_available') }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="fw-bolder mb-0">
                                {{ $lastLoad ? \Illuminate\Support\Carbon::parse($lastLoad)->diffForHumans() : '—' }}
                            </h2>
                            <span class="text-muted">{{ __('admin.import_last_load') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">

                    <div class="row g-1 mb-1">
                        <div class="col-md-6">
                            <div class="form-check border rounded p-1 ps-3 h-100">
                                <input class="form-check-input" type="radio" id="modeReplace"
                                       value="replace" wire:model="mode">
                                <label class="form-check-label d-block" for="modeReplace">
                                    <span class="fw-bolder">{{ __('admin.import_mode_replace') }}</span>
                                    <small class="d-block text-muted">{{ __('admin.import_mode_replace_hint') }}</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check border rounded p-1 ps-3 h-100">
                                <input class="form-check-input" type="radio" id="modeMerge"
                                       value="merge" wire:model="mode">
                                <label class="form-check-label d-block" for="modeMerge">
                                    <span class="fw-bolder">{{ __('admin.import_mode_merge') }}</span>
                                    <small class="d-block text-muted">{{ __('admin.import_mode_merge_hint') }}</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-1 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label" for="priceList">{{ __('admin.import_file') }}</label>
                            <input type="file" id="priceList"
                                   class="form-control @error('file') is-invalid @enderror"
                                   accept=".xlsx,.xls,.csv"
                                   wire:model="file">
                            @error('file') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-2">
                            <label class="form-label" for="sheet">{{ __('admin.import_sheet') }}</label>
                            <input type="number" id="sheet" min="1" class="form-control" wire:model="sheet">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label" for="skipRows">{{ __('admin.import_skip_rows') }}</label>
                            <input type="number" id="skipRows" min="0" class="form-control" wire:model="skipRows">
                        </div>

                        <div class="col-md-2">
                            <button type="button" class="btn btn-primary w-100"
                                    wire:click="confirmLoad"
                                    wire:loading.attr="disabled"
                                    @disabled(! $file)>
                                <span wire:loading.remove wire:target="confirmLoad, load">{{ __('admin.import_load') }}</span>
                                <span wire:loading wire:target="confirmLoad, load">{{ __('admin.import_loading') }}</span>
                            </button>
                        </div>
                    </div>

                    @if ($columns)
                        <p class="text-muted mt-1 mb-0">
                            {{ __('admin.import_columns') }}
                            @foreach ($columns as $role => $column)
                                @if (is_string($column))
                                    <span class="badge rounded-pill badge-light-secondary ms-50">{{ $role }}: {{ $column }}</span>
                                @endif
                            @endforeach
                        </p>
                    @endif
                </div>
            </div>

            @if ($result)
                <div class="card">
                    <div class="card-body">
                        <h4 class="mb-1">{{ __('admin.import_result') }}</h4>

                        <ul class="list-unstyled mb-1">
                            <li>{{ __('admin.import_rows_loaded', ['count' => $result['read']]) }}</li>
                            @if (($result['mode'] ?? 'replace') === 'merge')
                                <li class="text-muted">{{ __('admin.import_rows_kept') }}</li>
                            @else
                                <li>{{ __('admin.import_rows_zeroed', ['count' => $result['zeroed']]) }}</li>
                            @endif
                            @if ($result['missing_url'])
                                <li class="text-warning">
                                    {{ __('admin.import_rows_no_link', ['count' => $result['missing_url']]) }}
                                </li>
                            @endif
                            @if ($result['no_stock_column'] ?? false)
                                <li class="text-danger">{{ __('admin.import_no_stock_column') }}</li>
                            @endif
                        </ul>

                        @if ($result['read'])
                            <p class="text-muted">{{ __('admin.import_next_step') }}</p>

                            <button type="button" class="btn btn-success"
                                    wire:click="queueImport"
                                    wire:loading.attr="disabled">
                                {{ __('admin.import_queue') }}
                            </button>
                        @else
                            <p class="text-warning mb-0">{{ __('admin.import_nothing_read') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($confirming)
                <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
                     aria-labelledby="loadConfirmTitle" style="background:rgba(34,41,47,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="loadConfirmTitle">{{ __('admin.import_confirm_title') }}</h5>
                                <button type="button" class="btn-close" wire:click="cancelLoad"
                                        aria-label="{{ __('admin.cancel') }}"></button>
                            </div>

                            <div class="modal-body">
                                <p class="text-muted mb-50">{{ __('admin.import_confirm_into') }}</p>
                                <div class="border border-primary rounded p-1 mb-1 text-center">
                                    <h2 class="fw-bolder text-primary mb-0">{{ $supplier->name }}</h2>
                                    <small class="text-muted">{{ $supplier->code }}</small>
                                </div>

                                @if ($otherSupplier)
                                    <div class="alert alert-warning p-1 mb-1" role="alert">
                                        {{ __('admin.import_confirm_other', ['name' => $otherSupplier]) }}
                                    </div>
                                @endif

                                <dl class="row mb-0 small">
                                    <dt class="col-4">{{ __('admin.import_file') }}</dt>
                                    <dd class="col-8 text-break">{{ $file?->getClientOriginalName() }}</dd>

                                    <dt class="col-4">{{ __('admin.import_confirm_mode') }}</dt>
                                    <dd class="col-8">
                                        {{ $mode === 'merge' ? __('admin.import_mode_merge') : __('admin.import_mode_replace') }}
                                        <small class="d-block text-muted">
                                            {{ $mode === 'merge' ? __('admin.import_mode_merge_hint') : __('admin.import_mode_replace_hint') }}
                                        </small>
                                    </dd>

                                    <dt class="col-4">{{ __('admin.import_sheet') }}</dt>
                                    <dd class="col-8">{{ $sheet }} · {{ __('admin.import_skip_rows') }}: {{ $skipRows }}</dd>

                                    <dt class="col-4">{{ __('admin.import_confirm_now') }}</dt>
                                    <dd class="col-8 mb-0">
                                        {{ __('admin.import_confirm_held', [
                                            'rows' => number_format($loaded),
                                            'when' => $lastLoad ? \Illuminate\Support\Carbon::parse($lastLoad)->diffForHumans() : '—',
                                        ]) }}
                                    </dd>
                                </dl>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" wire:click="cancelLoad">
                                    {{ __('admin.cancel') }}
                                </button>
                                <button type="button" class="btn btn-primary" wire:click="load"
                                        wire:loading.attr="disabled" wire:target="load">
                                    {{ __('admin.import_confirm_yes', ['name' => $supplier->name]) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    @endif
</div>
