<div x-data="{
    busy: false, phase: '', stop: false, message: '', uploaded: 0, total: 0, cancelUpload: null,
    uploadErrors: @js($uploadErrors),
    async choose(file) {
        if (!file || this.busy) return;
        this.busy = true; this.phase = 'upload'; this.stop = false; this.message = ''; this.uploaded = 0; this.total = file.size;
        const controller = new AbortController();
        this.cancelUpload = () => controller.abort();
        try {
            await $wire.clearUpload();
            let module;
            try {
                module = await import(@js(route('admin.import.json-file.client', [], false).'?v=20261028-2'));
            } catch (error) {
                throw Object.assign(new Error(), { code: 'upload_client_missing' });
            }
            const uploader = module.createJsonUploader({
                csrfToken: document.querySelector('meta[name=csrf-token]')?.content,
                signal: controller.signal,
                onProgress: value => { this.uploaded = value.uploaded; this.total = value.total; }
            });
            const metadata = await uploader.upload(file, $wire.code);
            if (controller.signal.aborted) throw Object.assign(new Error(), { code: 'upload_cancelled' });
            const adopted = await $wire.useSource(metadata.id);
            if (!adopted?.ready || adopted.id !== metadata.id) throw new Error('invalid_source');
            if (controller.signal.aborted) throw Object.assign(new Error(), { code: 'upload_cancelled' });
            this.message = @js(__('admin.json_upload_ready'));
        } catch (error) {
            this.message = this.uploadErrors[error?.code] || @js(__('admin.json_upload_retry'));
        } finally { this.busy = false; this.phase = ''; this.cancelUpload = null; this.$refs.sourceFile.value = ''; }
    },
    async run(mode) {
        if (this.busy) return;
        this.busy = true; this.phase = mode; this.stop = false; this.message = '';
        try {
            let response;
            if (mode === 'look') {
                response = $wire.sourceId &amp;&amp; $wire.previewCursor > 0 &amp;&amp; !$wire.previewComplete
                    ? await $wire.previewNext() : await $wire.look();
            } else {
                response = $wire.sourceId &amp;&amp; $wire.saveStarted ? await $wire.saveNext() : await $wire.save();
            }
            if (!response || typeof response.done !== 'boolean' || !Number.isSafeInteger(response.processed)) throw new Error('invalid_step');
            while (!response.done &amp;&amp; !this.stop) {
                const previous = response.processed;
                response = mode === 'look' ? await $wire.previewNext() : await $wire.saveNext();
                if (!response || typeof response.done !== 'boolean' || !Number.isSafeInteger(response.processed)
                    || (!response.done &amp;&amp; response.processed &lt;= previous)) throw new Error('invalid_step');
            }
            this.message = this.stop ? @js(__('admin.json_bulk_paused')) : @js(__('admin.json_bulk_finished'));
        } catch (error) { this.message = @js(__('admin.json_bulk_check_errors')); }
        finally { this.busy = false; this.phase = ''; }
    },
    halt() { this.stop = true; this.cancelUpload?.(); this.message = @js(__('admin.json_bulk_stopping')); }
}">
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
                                :disabled="busy"
                                wire:model.live="code">
                            @foreach ($suppliers as $row)
                                <option value="{{ $row->code }}">{{ $row->name }}</option>
                            @endforeach
                        </select>
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="j-file">{{ __('admin.json_file_label') }}</label>
                        <input id="j-file" x-ref="sourceFile" type="file" accept=".json,application/json"
                               class="form-control @error('upload') is-invalid @enderror"
                               x-on:change="choose($event.target.files[0])" :disabled="busy">
                        @error('upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted d-block">{{ __('admin.json_file_hint') }}</small>
                        <div x-cloak x-show="phase === 'upload'" class="mt-50">
                            <span class="small">{{ __('admin.json_uploading') }} <span x-text="total ? Math.round(uploaded / total * 100) : 0"></span>%</span>
                            <progress class="w-100" :value="uploaded" :max="Math.max(total, 1)"></progress>
                        </div>
                        @if ($sourceName || $upload)
                            <div class="d-flex align-items-center gap-1 mt-50">
                                <span class="small text-break">{{ $sourceName ?: $upload?->getClientOriginalName() }}</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearUpload"
                                        :disabled="busy" wire:loading.attr="disabled">{{ __('admin.json_file_remove') }}</button>
                            </div>
                        @endif
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="j-json">{{ __('admin.json_label') }}</label>
                        <textarea id="j-json" rows="12"
                                  class="form-control font-monospace small @error('json') is-invalid @enderror"
                                  wire:model.blur="json"
                                  :disabled="busy || Boolean($wire.sourceId)" @disabled($upload !== null || $sourceId !== null)
                                  placeholder='{"product":{"id":45491,"barCode":"169226", …}}'></textarea>
                        @error('json') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted">{{ __('admin.json_separators') }}</small>
                    </div>

                    <button type="button" class="btn btn-outline-primary"
                            x-on:click="run('look')" :disabled="busy" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="look">{{ __('admin.json_look') }}</span>
                        <span wire:loading wire:target="look">{{ __('admin.json_look') }}…</span>
                    </button>

                    <button type="button" class="btn btn-outline-secondary ms-50" x-cloak x-show="busy" x-on:click="halt()">
                        {{ __('admin.json_bulk_pause') }}
                    </button>
                    <p class="small mt-1 mb-0" x-cloak x-show="message" x-text="message" role="status"></p>
                    @if ($sourceId)
                        <p class="small mt-1 mb-0" role="status">
                            {{ __('admin.json_scan_progress', ['count' => $totalRecords, 'valid' => $validRecords]) }}
                            @if ($previewComplete) · {{ __('admin.json_scan_complete') }} @endif
                        </p>
                        @if ($saveStarted || $saveComplete)
                            <p class="small mb-0">{{ __('admin.json_save_progress', ['count' => $saveProcessed, 'total' => $totalRecords, 'saved' => $savedCount]) }}</p>
                        @endif
                    @endif

                    @if (count($problems))
                        <p class="small text-danger mt-1 mb-0">{{ __('admin.json_error_summary', ['count' => $errorCount]) }}</p>
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
                            {{ __($photoWarnings ? 'admin.json_saved_with_photo_warnings' : 'admin.json_saved_many', ['count' => $savedCount ?: count($result)]) }}
                        </h5>
                    </div>

                    <div class="card-body">
                        @if ($savedCount > count($result))
                            <p class="small text-muted">{{ __('admin.json_results_recent') }}</p>
                        @endif
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
                            <span class="badge rounded-pill badge-light-primary ms-50">{{ $totalRecords }}</span>
                        </h4>
                    </div>

                    <div class="card-body">
                        @if ($totalRecords > count($preview))
                            <p class="small text-muted">{{ __('admin.json_preview_sample') }}</p>
                        @endif
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
                                        {{-- the shelf Alta files it under, parent first when it names one --}}
                                        @if ($row['parent_category'] ?? null)
                                            {{ $row['parent_category'] }} &rsaquo;
                                        @endif
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
                                           :disabled="busy"
                                           wire:model.live.debounce.400ms="price">
                                    @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-6 mb-1">
                                    <label class="form-label" for="j-stock">{{ __('admin.json_our_stock') }}</label>
                                    <input id="j-stock" type="number" min="0"
                                           class="form-control @error('stock') is-invalid @enderror"
                                           :disabled="busy"
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
                                x-on:click="run('save')" :disabled="busy || !$wire.previewFingerprint" wire:loading.attr="disabled" @disabled($previewFingerprint === null)>
                            <span wire:loading.remove wire:target="save">
                                {{ $saveStarted ? __('admin.json_bulk_continue') : __('admin.json_save_many', ['count' => $validRecords]) }}
                            </span>
                            <span wire:loading wire:target="save">
                                {{ __('admin.json_save_many', ['count' => $validRecords]) }}…
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
