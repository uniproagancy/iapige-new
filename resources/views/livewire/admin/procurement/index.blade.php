<div class="pr-board" x-data="{ async copyReview(text) { try { await navigator.clipboard.writeText(text); $dispatch('procurement-copy-result', { success: true }); } catch (e) { $dispatch('procurement-copy-result', { success: false }); } } }">
    <style>
        .pr-board{--pr-accent:#ff7b00;--pr-line:rgba(145,158,184,.22);--pr-soft:rgba(145,158,184,.07)}
        .pr-board .pr-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem}
        .pr-board .pr-private{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;background:var(--pr-soft);border:1px solid var(--pr-line);border-radius:30px;padding:.35rem .75rem}
        .pr-board .pr-private:before{content:"";height:6px;width:6px;border-radius:50%;background:var(--pr-accent)}
        .pr-board .pr-counts{display:flex;flex-wrap:wrap;gap:.55rem;margin:1rem 0 1.4rem}
        .pr-board .pr-count{border:1px solid var(--pr-line);background:var(--pr-soft);border-radius:10px;padding:.65rem 1rem;min-width:105px}
        .pr-board .pr-count strong{font-size:1.3rem;display:block;line-height:1.25}.pr-board .pr-count span{font-size:.8rem}
        .pr-board .pr-count.pr-alert{border-color:rgba(234,84,85,.45)}
        .pr-board .pr-tools{display:flex;gap:.65rem;align-items:center;flex-wrap:wrap}.pr-board .pr-search{flex:1;min-width:210px}
        .pr-board .pr-status{width:auto;min-width:180px}.pr-board .pr-dates{border-top:1px solid var(--pr-line);margin-top:1rem;padding-top:1rem}
        .pr-board .pr-layout{display:grid;grid-template-columns:210px minmax(0,1fr);gap:1.4rem;align-items:start}
        .pr-board .pr-sidebar{position:sticky;top:1rem;border:1px solid var(--pr-line);border-radius:12px;padding:.45rem}
        .pr-board .pr-supplier{width:100%;border:0;background:transparent;text-align:left;border-radius:8px;color:inherit;padding:.75rem .9rem;font:inherit;overflow-wrap:anywhere}
        .pr-board .pr-supplier:hover{background:var(--pr-soft)}.pr-board .pr-supplier.is-active{background:rgba(255,123,0,.12);box-shadow:inset 3px 0 var(--pr-accent);color:var(--pr-accent)}
        .pr-board .pr-group{border:1px solid var(--pr-line);border-radius:14px;overflow:hidden;margin-bottom:1.25rem}
        .pr-board .pr-group-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1.2rem 1.3rem;background:var(--pr-soft);flex-wrap:wrap;border-bottom:1px solid var(--pr-line)}
        .pr-board .pr-group-head h4{margin:0}.pr-board .pr-group-body{padding:0 1.3rem 1.1rem}
        .pr-board .pr-summary{width:100%;border-collapse:collapse}.pr-board .pr-summary th{font-size:.75rem;font-weight:500;opacity:.7;padding:.8rem .5rem}.pr-board .pr-summary td{padding:.85rem .5rem;border-top:1px solid var(--pr-line);vertical-align:top}
        .pr-board .pr-summary th:first-child,.pr-board .pr-summary td:first-child{padding-left:0}.pr-board .pr-summary th:last-child,.pr-board .pr-summary td:last-child{padding-right:0;text-align:right;white-space:nowrap}
        .pr-board .pr-model{font-weight:600;overflow-wrap:anywhere}.pr-board .pr-code{font-family:inherit;font-size:.86rem;overflow-wrap:anywhere}.pr-board .pr-qty{font-size:1.05rem;font-weight:700;color:var(--pr-accent)}
        .pr-board .pr-detail{margin-top:1rem}.pr-board .pr-detail>summary{cursor:pointer;font-size:.85rem;padding:.5rem 0}.pr-board .pr-line{padding:1rem 0;border-top:1px solid var(--pr-line)}
        .pr-board .pr-line-head{display:flex;justify-content:space-between;gap:.6rem;align-items:flex-start;flex-wrap:wrap}.pr-board .pr-line-actions{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.65rem}
        .pr-board .pr-badge{display:inline-block;border-radius:6px;padding:.28rem .55rem;font-size:.75rem;font-weight:600;background:var(--pr-soft);white-space:nowrap}
        .pr-board .pr-badge-unsent{background:rgba(255,159,67,.15);color:#ffb366}.pr-board .pr-badge-sent{background:rgba(0,207,232,.13);color:#48dcea}.pr-board .pr-badge-confirmed{background:rgba(115,103,240,.17);color:#a39aff}.pr-board .pr-badge-received,.pr-board .pr-badge-resolved{background:rgba(40,199,111,.14);color:#35cb83}.pr-board .pr-badge-issue,.pr-board .pr-badge-withdraw{background:rgba(234,84,85,.14);color:#ff8787}
        .pr-board .pr-badge-mixed{background:rgba(145,158,184,.15);color:#c6cfdf}.pr-board .pr-summary-status{margin-top:.4rem}.pr-board .pr-summary .pr-quantity{text-align:right;white-space:nowrap}.pr-board .pr-summary .pr-order-actions{white-space:normal}.pr-board .pr-order-actions .btn{white-space:nowrap}
        .pr-board .pr-change{border-left:3px solid #ea5455;padding:.7rem .9rem;background:rgba(234,84,85,.07);border-radius:0 8px 8px 0;margin:.75rem 0}
        .pr-board .pr-order{display:flex;gap:.45rem 1rem;flex-wrap:wrap;font-size:.83rem;margin-top:.6rem}.pr-board .pr-order span{overflow-wrap:anywhere}.pr-board .pr-note{white-space:pre-line;font-size:.86rem;background:var(--pr-soft);padding:.65rem .8rem;border-radius:7px;margin:.7rem 0 0;overflow-wrap:anywhere}
        .pr-board .pr-review,.pr-board .pr-editor{border:1px solid rgba(255,123,0,.55);border-radius:12px;padding:1.3rem;margin-bottom:1.4rem;background:var(--pr-soft)}
        .pr-board .pr-review textarea{min-height:150px;line-height:1.7;resize:vertical}.pr-board .pr-editor .form-control,.pr-board .pr-editor .form-select{min-height:40px}.pr-board .pr-edit-supplier{border-top:1px solid var(--pr-line);padding-top:1rem;margin-top:1.2rem}
        .pr-board .pr-empty{text-align:center;padding:3.5rem 1rem;border:1px dashed var(--pr-line);border-radius:12px}.pr-board .pr-muted{opacity:.72}
        @media(max-width:900px){.pr-board .pr-layout{grid-template-columns:1fr}.pr-board .pr-sidebar{position:static;display:flex;overflow-x:auto;gap:.3rem}.pr-board .pr-supplier{width:auto;flex-shrink:0;max-width:220px;white-space:nowrap}.pr-board .pr-count{min-width:90px;flex:1}.pr-board .pr-group-head,.pr-board .pr-group-body{padding-left:1rem;padding-right:1rem}}
        @media(max-width:575px){.pr-board .pr-status{width:100%}.pr-board .pr-summary td,.pr-board .pr-summary th{font-size:.82rem}.pr-board .pr-count{padding:.55rem .7rem}.pr-board .pr-header h2{font-size:1.4rem}.pr-board .pr-group-head .btn{width:100%}.pr-board .pr-group-head{gap:.7rem}}
        @media(max-width:575px){.pr-board .pr-summary thead{display:none}.pr-board .pr-summary tbody{display:block}.pr-board .pr-summary tr{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.55rem .75rem;border-top:1px solid var(--pr-line);padding:.9rem 0}.pr-board .pr-summary td{display:block;border:0;padding:0}.pr-board .pr-summary td:first-child{grid-column:1;grid-row:1}.pr-board .pr-summary .pr-code{grid-column:1;grid-row:2}.pr-board .pr-summary .pr-quantity{grid-column:2;grid-row:1}.pr-board .pr-summary .pr-order-actions{grid-column:2;grid-row:2;align-self:end}}
    </style>

    @php
        $allRows = collect($board['groups'] ?? [])->flatMap(fn ($group) => $group['rows'] ?? []);
        $attention = $allRows->filter(fn ($row) => ! ($row['handled'] ?? false) && (($row['type'] ?? '') === 'withdraw' || ($row['status'] ?? '') === 'issue'))->count();
        $statusChoices = ['outstanding', 'all', 'unsent', 'sent', 'confirmed', 'received', 'issue'];
        $editableStatuses = ($editingWasSent ?? false)
            ? ['unsent', 'sent', 'confirmed', 'received', 'issue'] : ['unsent', 'confirmed', 'received', 'issue'];
    @endphp

    <div class="pr-header">
        <div><span class="pr-private mb-50">{{ __('procurement.private') }}</span><h2 class="mb-50">{{ __('procurement.title') }}</h2><p class="pr-muted mb-0">{{ __('procurement.subtitle') }}</p></div>
        <button type="button" class="btn btn-outline-secondary" wire:click="refreshBoard" wire:loading.attr="disabled">{{ __('procurement.refresh') }}</button>
    </div>

    @if ($notice)<div class="alert alert-success" role="status">{{ $notice }}</div>@endif
    @if ($errors->any())<div class="alert alert-warning" role="alert"><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @if ($board['truncated'] ?? false)<div class="alert alert-info" role="status">{{ __('procurement.truncated') }}</div>@endif

    <div class="pr-counts">
        <div class="pr-count"><strong>{{ count($board['groups'] ?? []) }}</strong><span>{{ __('procurement.summary_suppliers') }}</span></div>
        <div class="pr-count"><strong>{{ collect($board['groups'] ?? [])->sum(fn ($group) => count($group['summary'] ?? [])) }}</strong><span>{{ __('procurement.summary_models') }}</span></div>
        <div class="pr-count"><strong>{{ $allRows->where('type', 'current')->sum('qty') }}</strong><span>{{ __('procurement.summary_units') }}</span></div>
        <div class="pr-count {{ $attention ? 'pr-alert' : '' }}"><strong>{{ $attention }}</strong><span>{{ __('procurement.summary_attention') }}</span></div>
    </div>

    <div class="card"><div class="card-body">
        <div class="pr-tools">
            <div class="pr-search"><label class="visually-hidden" for="procurement-search">{{ __('procurement.search') }}</label><input id="procurement-search" type="search" class="form-control" wire:model.live.debounce.350ms="search" maxlength="120" placeholder="{{ __('procurement.search') }}"></div>
            <label class="visually-hidden" for="procurement-status">{{ __('procurement.status') }}</label>
            <select id="procurement-status" class="form-select pr-status" wire:model.live="status">@foreach ($statusChoices as $choice)<option value="{{ $choice }}">{{ in_array($choice, ['outstanding', 'all'], true) ? __('procurement.'.$choice) : __('procurement.statuses.'.$choice) }}</option>@endforeach</select>
            <button type="button" class="btn btn-outline-secondary" wire:click="clearFilters">{{ __('procurement.clear') }}</button>
        </div>
        <details class="mt-1"><summary class="small" style="cursor:pointer">{{ __('procurement.filters') }}</summary><div class="row g-1 pr-dates">
            <div class="col-sm-6"><label class="form-label" for="procurement-from">{{ __('procurement.from') }}</label><input id="procurement-from" type="date" class="form-control" wire:model.live="from"></div>
            <div class="col-sm-6"><label class="form-label" for="procurement-to">{{ __('procurement.to') }}</label><input id="procurement-to" type="date" class="form-control" wire:model.live="to"></div>
        </div></details>
    </div></div>

    @if ($reviewText !== '')
        <section class="pr-review" wire:key="procurement-review-{{ hash('sha256', $reviewText) }}" x-data="{ copied: false, failed: false }" x-on:procurement-copy-result.window="copied = $event.detail.success; failed = !copied">
            <div class="d-flex justify-content-between align-items-start gap-1"><div><h4>{{ __('procurement.review_title') }} — {{ $reviewSupplier }}</h4><p class="small pr-muted">{{ __('procurement.review_help') }}</p></div><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closeReview">{{ __('procurement.close') }}</button></div>
            <label class="visually-hidden" for="procurement-copy-text">{{ __('procurement.review_title') }}</label><textarea id="procurement-copy-text" class="form-control mb-1" readonly x-ref="message">{{ $reviewText }}</textarea>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <button type="button" class="btn btn-primary" x-on:click="try { await navigator.clipboard.writeText($refs.message.value); copied = true; failed = false; } catch (e) { $refs.message.focus(); $refs.message.select(); failed = true; }"><span x-show="!copied">{{ __('procurement.copy_button') }}</span><span x-show="copied" x-cloak>{{ __('procurement.copied') }}</span></button>
                @if ($reviewManifest !== [])<button type="button" class="btn btn-outline-success" wire:click="markSent" wire:loading.attr="disabled">{{ __('procurement.mark_sent') }}</button>@endif
                <span class="small" x-show="failed" x-cloak>{{ __('procurement.copy_failed') }}</span>
            </div>
            @if ($reviewManifest !== [])<p class="small pr-muted mt-1 mb-0">{{ __('procurement.sent_help') }}</p>@endif
        </section>
    @endif

    @if ($editingItemId !== null)
        <section class="pr-editor" wire:key="procurement-editor-{{ $editingItemId }}">
            <div class="d-flex justify-content-between align-items-start gap-1"><div><h4>{{ __('procurement.edit_title') }}</h4><p class="pr-model">{{ $editingModel }}</p></div><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closeEdit">{{ __('procurement.close') }}</button></div>
            <form wire:submit="saveItem"><div class="row g-1">
                <div class="col-md-6"><label class="form-label" for="procurement-edit-status">{{ __('procurement.status') }}</label><select id="procurement-edit-status" class="form-select" wire:model="form.status">@foreach ($editableStatuses as $choice)<option value="{{ $choice }}">{{ __('procurement.statuses.'.$choice) }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="procurement-edit-issue-qty">{{ __('procurement.issue_qty') }}</label><input id="procurement-edit-issue-qty" type="number" min="0" step="1" max="1000000" class="form-control" wire:model="form.issue_qty"></div>
                <div class="col-12"><label class="form-label" for="procurement-edit-native">{{ __('procurement.native_sku') }}</label><input id="procurement-edit-native" class="form-control" maxlength="191" wire:model="form.native_sku"><small class="pr-muted">{{ __('procurement.native_help') }}</small></div>
                <div class="col-12"><label class="form-label" for="procurement-edit-note">{{ __('procurement.note') }}</label><textarea id="procurement-edit-note" class="form-control" maxlength="2000" rows="3" wire:model="form.note"></textarea></div>
            </div><button type="submit" class="btn btn-primary mt-1" wire:loading.attr="disabled">{{ __('procurement.save') }}</button></form>
            <div class="pr-edit-supplier"><label class="form-label" for="procurement-new-supplier">{{ __('procurement.supplier_edit') }}</label><p class="small pr-muted">{{ __('procurement.supplier_help') }}</p><div class="d-flex flex-wrap gap-1"><select id="procurement-new-supplier" class="form-select" style="max-width:420px" wire:model="newSupplierId"><option value="">{{ __('procurement.select_supplier') }}</option>@foreach ($board['suppliers'] ?? [] as $supplier)<option value="{{ $supplier['id'] }}">{{ $supplier['name'] }}</option>@endforeach</select><button type="button" class="btn btn-outline-primary" wire:click="changeSupplier" wire:loading.attr="disabled">{{ __('procurement.supplier_edit') }}</button></div></div>
        </section>
    @endif

    <div class="pr-layout">
        <aside class="pr-sidebar" aria-label="{{ __('procurement.supplier') }}">
            <button type="button" class="pr-supplier {{ $supplierId === '' ? 'is-active' : '' }}" wire:click="chooseSupplier('')">{{ __('procurement.all_suppliers') }}</button>
            @foreach ($board['suppliers'] ?? [] as $supplier)<button type="button" class="pr-supplier {{ $supplierId === (string) $supplier['id'] ? 'is-active' : '' }}" wire:click="chooseSupplier('{{ $supplier['id'] }}')">{{ $supplier['name'] }}</button>@endforeach
        </aside>

        <main>
            @forelse ($board['groups'] ?? [] as $group)
                <section class="pr-group" wire:key="procurement-group-{{ $group['key'] }}">
                    <div class="pr-group-head"><div><h4>{{ $group['name'] }}</h4><span class="small pr-muted">{{ count($group['summary'] ?? []) }} {{ __('procurement.models') }}</span></div>
                        @if (($group['copy_text'] ?? '') !== '')<button type="button" class="btn btn-primary" x-on:click="await copyReview(await $wire.reviewGroup(@js($group['key'])))" wire:loading.attr="disabled">{{ __('procurement.copy_supplier') }}</button>@endif
                    </div>
                    <div class="pr-group-body">
                        @if (($group['summary'] ?? []) !== [])
                            <table class="pr-summary"><thead><tr><th>{{ __('procurement.model') }}</th><th>{{ __('procurement.code') }}</th><th>{{ __('procurement.quantity') }}</th><th>{{ __('procurement.actions') }}</th></tr></thead><tbody>
                                @foreach ($group['summary'] as $summary)
                                    @php($summaryStatus = $summary['status'] ?? 'unsent')
                                    <tr wire:key="procurement-model-{{ $summary['key'] ?? hash('sha256', $summary['model'].'|'.$summary['code']) }}">
                                        <td><div class="pr-model">{{ $summary['model'] }}</div><div class="pr-summary-status"><span class="pr-badge pr-badge-{{ $summaryStatus }}">{{ __('procurement.statuses.'.$summaryStatus) }}</span></div></td>
                                        <td class="pr-code">@if (filled($summary['code'] ?? null)){{ $summary['code'] }}@if (($summary['code_label'] ?? '') === 'reference')<small class="d-block pr-muted">{{ __('procurement.reference') }}</small>@endif @else<span class="small pr-muted">{{ __('procurement.no_code') }}</span>@endif</td>
                                        <td class="pr-quantity"><span class="pr-qty">{{ $summary['qty'] }}</span> <small>{{ __('procurement.units') }}</small></td>
                                        <td class="pr-order-actions">@if (($summary['order_manifest'] ?? []) !== [])<button type="button" class="btn btn-sm btn-primary" wire:click="markModelOrdered(@js($summary['order_manifest']))" wire:loading.attr="disabled">{{ __('procurement.mark_ordered') }}</button>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody></table>
                        @endif

                        @foreach ($group['rows'] as $row)
                            @if (($row['type'] ?? '') === 'withdraw')
                                <div class="pr-change" wire:key="procurement-task-{{ $row['key'] }}"><div class="d-flex flex-wrap justify-content-between gap-1"><strong>{{ $row['model'] }} × {{ $row['qty'] }}</strong><span class="pr-badge pr-badge-withdraw">{{ __('procurement.change_needed') }}</span></div><p class="small mt-50 mb-50">{{ ($row['source_reason'] ?? '') !== '' && __('procurement.reasons.'.$row['source_reason']) !== 'procurement.reasons.'.$row['source_reason'] ? __('procurement.reasons.'.$row['source_reason']) : __('procurement.changed_source') }}</p>
                                    @foreach ($row['orders'] ?? [] as $order)<div class="pr-order"><span>{{ $order['number'] }}</span><span>{{ $order['date'] ?? '' }}</span></div>@endforeach
                                    @if (filled($row['note'] ?? null))<p class="pr-note">{{ $row['note'] }}</p>@endif
                                    <p class="small pr-muted mt-50 mb-50">{{ __('procurement.acknowledge_help') }}</p><div class="d-flex flex-wrap gap-50">@if (($row['copy_text'] ?? '') !== '')<button type="button" class="btn btn-sm btn-outline-primary" x-on:click="await copyReview(await $wire.reviewRow(@js($row['key'])))">{{ __('procurement.copy_change') }}</button>@endif<button type="button" class="btn btn-sm btn-outline-danger" wire:click="acknowledge('{{ $row['key'] }}', '{{ $row['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement.acknowledge') }}</button></div>
                                </div>
                            @endif
                        @endforeach

                        <details class="pr-detail"><summary>{{ __('procurement.orders') }} · {{ count(array_filter($group['rows'], fn ($row) => ($row['type'] ?? '') === 'current')) }}</summary>
                            @foreach ($group['rows'] as $row)
                                @if (($row['type'] ?? '') === 'current')
                                    @php($displayStatus = ($row['handled'] ?? false) ? 'resolved' : $row['status'])
                                    <div class="pr-line" wire:key="procurement-row-{{ $row['key'] }}">
                                        <div class="pr-line-head"><div><span class="pr-model">{{ $row['model'] }}</span> <strong>× {{ $row['qty'] }}</strong>@if (filled($row['code'] ?? null))<div class="small pr-muted">{{ ($row['code_label'] ?? '') === 'reference' ? __('procurement.reference') : __('procurement.code') }}: {{ $row['code'] }}</div>@endif</div><span class="pr-badge pr-badge-{{ $displayStatus }}">{{ __('procurement.statuses.'.$displayStatus) }}</span></div>
                                        @if (! ($row['available'] ?? true))<p class="small text-warning mt-50 mb-0">{{ __('procurement.unavailable') }}</p>@endif
                                        @foreach ($row['orders'] ?? [] as $order)<div class="pr-order"><span><strong>{{ $order['number'] }}</strong></span><span>{{ $order['name'] ?? '' }}</span><span>{{ $order['phone'] ?? '' }}</span><span>{{ $order['date'] ?? '' }}</span></div>@endforeach
                                        @if (filled($row['note'] ?? null))<p class="pr-note">{{ $row['note'] }}</p>@endif
                                        @if (($row['issue_qty'] ?? 0) > 0)<p class="small text-danger mt-50 mb-0">{{ __('procurement.issue_qty') }}: {{ $row['issue_qty'] }}</p>@endif
                                        <div class="pr-line-actions">@if (($row['available'] ?? false) && $row['status'] === 'unsent')<button type="button" class="btn btn-sm btn-primary" wire:click="markRowOrdered('{{ $row['key'] }}', '{{ $row['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement.mark_ordered') }}</button>@endif<button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editRow('{{ $row['key'] }}')">{{ __('procurement.edit') }}</button>@if (($row['available'] ?? true) && ($row['copy_text'] ?? '') !== '')<button type="button" class="btn btn-sm btn-outline-primary" x-on:click="await copyReview(await $wire.reviewRow(@js($row['key'])))">{{ __('procurement.copy_row') }}</button>@endif</div>
                                    </div>
                                @endif
                            @endforeach
                        </details>
                    </div>
                </section>
            @empty
                <div class="pr-empty"><h4>{{ __('procurement.empty_title') }}</h4><p class="pr-muted mb-0">{{ __('procurement.empty_help') }}</p></div>
            @endforelse
        </main>
    </div>
</div>
