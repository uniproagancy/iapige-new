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
                    <button type="button" class="btn btn-primary" wire:click="run" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="run">{{ __('admin.run_start') }}</span>
                        <span wire:loading wire:target="run">{{ __('admin.run_start') }}…</span>
                    </button>
                </div>
            </div>

            @if ($queued)
                <div class="alert alert-info p-1 mb-0">
                    {{ __('admin.run_worker_note', ['count' => $queued]) }}
                    <code class="d-block mt-50">php artisan queue:work --queue=import --timeout=360</code>
                </div>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------------ every supplier --}}
    <div class="card">
        <div class="card-header">
            <h4 class="card-title">{{ __('admin.run_all') }}</h4>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
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
                                <b>{{ $supplier->name }}</b>
                                <small class="d-block text-muted">{{ $supplier->code }}</small>
                            </td>

                            <td class="text-center">{{ $row['rows'] ?: '—' }}</td>
                            <td class="text-center">{{ $row['products'] ?: '—' }}</td>

                            <td class="text-center">
                                @if ($row['waiting'])
                                    <span class="badge badge-light-info">{{ $row['waiting'] }}</span>
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
