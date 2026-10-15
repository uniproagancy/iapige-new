<div>
    {{-- ------------------------------------------------------------ pick one and go --}}
    <div class="card">
        <div class="card-header">
            <h4 class="card-title">{{ __('admin.run_title') }}</h4>
            <small class="text-muted">{{ __('admin.run_hint') }}</small>
        </div>

        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-5 mb-1">
                    <label class="form-label" for="r-supplier">{{ __('admin.supplier') }}</label>
                    <select id="r-supplier" class="form-select" wire:model="picked">
                        <option value="">{{ __('admin.run_choose') }}</option>
                        @foreach ($suppliers as $row)
                            <option value="{{ $row['model']->code }}">{{ $row['model']->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-7 mb-1">
                    <button type="button" class="btn btn-primary" wire:click="run" wire:loading.attr="disabled"
                            @if ($picked && ($busy[$picked] ?? 0))
                                wire:confirm="{{ __('admin.run_again_confirm', ['count' => $busy[$picked]]) }}"
                            @endif>
                        <span wire:loading.remove wire:target="run">{{ __('admin.run_start') }}</span>
                        <span wire:loading wire:target="run">{{ __('admin.run_start') }}…</span>
                    </button>
                </div>
            </div>

            @if ($queued)
                <div class="alert alert-info p-1 mb-0">
                    {{ __('admin.run_worker_note', ['count' => $queued]) }}
                    <code class="d-block mt-50">{{ $command }}</code>
                </div>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------------ every supplier --}}
    {{-- --------------------------------------------------------- working here --}}
    @if ($working)
        @php
            $left = $busy[$working] ?? 0;
            $percent = $startedWith > 0 ? (int) round($processed / $startedWith * 100) : 0;
        @endphp

        <div class="card border-primary" wire:poll.700ms="processNext">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-50">
                    <span><b>{{ $working }}</b> — {{ __('admin.run_working', ['done' => $processed, 'total' => $startedWith]) }}</span>
                    <span class="text-muted">{{ __('admin.run_left', ['count' => $left]) }}</span>
                </div>

                <div class="progress mb-1" style="height:8px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                         style="width: {{ $percent }}%"></div>
                </div>

                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="stopWorking">{{ __('admin.links_stop') }}</button>

                <small class="text-muted ms-1">{{ __('admin.run_working_hint') }}</small>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h4 class="card-title">{{ __('admin.run_all') }}</h4>

            @if ($selected)
                <div class="d-flex align-items-center gap-1">
                    <span class="text-muted">{{ __('admin.n_selected', ['count' => count($selected)]) }}</span>

                    @php $busySelected = collect($selected)->sum(fn ($c) => $busy[$c] ?? 0); @endphp

                    <button type="button" class="btn btn-sm btn-primary"
                            wire:click="runSelected" wire:loading.attr="disabled"
                            @if ($busySelected)
                                wire:confirm="{{ __('admin.run_again_confirm', ['count' => $busySelected]) }}"
                            @endif>
                        <span wire:loading.remove wire:target="runSelected">{{ __('admin.run_selected') }}</span>
                        <span wire:loading wire:target="runSelected">{{ __('admin.run_selected') }}…</span>
                    </button>
                </div>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:40px">
                            <input type="checkbox" class="form-check-input" wire:model.live="selectAll">
                        </th>
                        <th>{{ __('admin.supplier') }}</th>
                        <th class="text-center">{{ __('admin.run_rows') }}</th>
                        <th class="text-center">{{ __('admin.run_products') }}</th>
                        <th class="text-center">{{ __('admin.run_waiting') }}</th>
                        <th class="text-center">{{ __('admin.run_failed') }}</th>
                        <th>{{ __('admin.run_last') }}</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($suppliers as $row)
                        @php $supplier = $row['model']; @endphp

                        <tr wire:key="s-{{ $supplier->id }}" @class(['opacity-50' => ! $supplier->is_active])>
                            <td>
                                <input type="checkbox" class="form-check-input"
                                       value="{{ $supplier->code }}" wire:model.live="selected"
                                       @disabled(! $supplier->is_active)>
                            </td>
                            <td>
                                <b>{{ $supplier->name }}</b>
                                <small class="d-block text-muted">{{ $supplier->code }}</small>
                            </td>

                            <td class="text-center">{{ $row['rows'] ?: '—' }}</td>
                            <td class="text-center">{{ $row['products'] ?: '—' }}</td>

                            <td class="text-center">
                                @if ($row['waiting'])
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-success"
                                                wire:click="workHere('{{ $supplier->code }}')"
                                                title="{{ __('admin.run_work_here') }}"
                                                @disabled($working)>
                                            ▸ {{ $row['waiting'] }}
                                        </button>

                                        <button type="button" class="btn btn-outline-danger"
                                                wire:click="clearQueue('{{ $supplier->code }}')"
                                                wire:confirm="{{ __('admin.run_queue_clear_confirm', ['count' => $row['waiting']]) }}"
                                                title="{{ __('admin.run_queue_clear') }}">×</button>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>

                            <td class="text-center">
                                @if ($row['failed'])
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="clearFailed('{{ $supplier->code }}')"
                                            wire:confirm="{{ __('admin.confirm_delete') }}">
                                        {{ $row['failed'] }} ×
                                    </button>
                                @else
                                    —
                                @endif
                            </td>

                            <td>
                                <small class="text-muted">
                                    {{ $supplier->last_run_at ? $supplier->last_run_at->diffForHumans() : __('admin.run_never') }}
                                </small>
                            </td>

                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        wire:click="run('{{ $supplier->code }}')"
                                        wire:loading.attr="disabled"
                                        @if ($row['waiting'])
                                            wire:confirm="{{ __('admin.run_again_confirm', ['count' => $row['waiting']]) }}"
                                        @endif
                                        @disabled(! $supplier->is_active)>
                                    {{ __('admin.run_start') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
