<div wire:poll.30s.visible>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
        <a href="{{ route('admin.warranties.index') }}" class="btn btn-outline-secondary" wire:navigate>{{ __('warranty.back_to_list') }}</a>
        <div class="d-flex flex-wrap align-items-center gap-1">
            <span class="badge badge-light-{{ $certificate->status === 'issued' ? 'success' : ($certificate->status === 'revoked' ? 'danger' : 'warning') }}">{{ __('warranty.statuses.'.$certificate->status) }}</span>
            <span class="text-muted">{{ __('warranty.revision') }} {{ $certificate->revision }}</span>
        </div>
    </div>

    @if (session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if (session('status'))<div class="alert alert-info" role="status">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <x-warranty.list-card :title="__('warranty.certificate_title', ['number' => $certificate->number])">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('admin.warranties.print', $certificate) }}" target="_blank" rel="noopener">{{ __('warranty.print') }}</a>
            @if ($isDraft)
                <a class="btn btn-outline-primary" href="{{ route('admin.warranties.preview', $certificate) }}" target="_blank" rel="noopener">{{ __('warranty.preview_draft') }}</a>
            @elseif ($certificate->status === 'issued')
                <a class="btn btn-primary" href="{{ route('admin.warranties.pdf', $certificate) }}">{{ __('warranty.download_pdf') }}</a>
            @endif
            @if ($certificate->order)
                <a class="btn btn-outline-secondary" href="{{ route('admin.orders.show', $certificate->order) }}" wire:navigate>{{ __('warranty.open_order') }}</a>
            @endif
        </x-slot:actions>
        <div class="card-body pt-0">
            <div class="row g-1 mb-2">
                <div class="col-md-6">
                    <small class="text-muted d-block">{{ __('warranty.product') }}</small>
                    <h4 class="mb-50">{{ $snapshot['item']['name'] ?? $certificate->deliveryItem?->name ?? '—' }}</h4>
                    <span class="d-block">{{ __('warranty.supplier_sku') }}: {{ ($snapshot['item']['supplier_sku'] ?? '') ?: '—' }}</span>
                    <p class="text-muted mb-0">{{ __('warranty.unit_index') }}: {{ $certificate->unit_index }} · {{ __('warranty.quantity') }}: 1</p>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">{{ __('warranty.customer') }}</small>
                    <strong>{{ $certificate->customer_name }}</strong>
                    <span class="d-block">{{ $certificate->customer_phone }}</span>
                    <span class="text-muted">{{ $snapshot['item']['order_number'] ?? $certificate->order?->number }}</span>
                </div>
            </div>

            @if ($isDraft)
                @php
                    $warrantyPreview = $conditionsPreview ?? [
                        'service' => data_get($snapshot, 'warranty.service', []),
                        'coverage' => data_get($snapshot, 'warranty.coverage', ''),
                        'conditions' => data_get($snapshot, 'warranty.conditions', ''),
                        'claim_instructions' => data_get($snapshot, 'warranty.claim_instructions', ''),
                        'missing_fields' => [],
                        'auto_ready' => (bool) data_get($snapshot, 'warranty.policy_approved', false),
                    ];
                @endphp
                <div class="border rounded p-1 mb-1">
                    <strong>{{ __('warranty.conditions_preview') }}</strong>
                    <small class="text-muted d-block">{{ __('warranty.conditions_preview_help') }}</small>
                    @if ($isOwner && $certificate->deliveryItem?->supplier)
                        <a class="btn btn-sm btn-outline-primary mt-50" href="{{ route('admin.warranties.services', ['supplier' => $certificate->deliveryItem->supplier->code]) }}" target="_blank" rel="noopener noreferrer">{{ __('warranty.edit_supplier_terms') }}</a>
                    @endif
                    <div class="mt-50">{{ data_get($warrantyPreview, 'service.name') ?: '—' }}</div>
                    @foreach (['phone', 'email', 'address', 'scope'] as $contactField)
                        @if (filled(data_get($warrantyPreview, 'service.'.$contactField)))<span class="d-block">{{ data_get($warrantyPreview, 'service.'.$contactField) }}</span>@endif
                    @endforeach
                    @if (! ($warrantyPreview['auto_ready'] ?? false))
                        <div class="alert alert-warning py-50 mt-1 mb-0" role="status">{{ __('warranty.supplier_warranty_incomplete') }}@if (! empty($warrantyPreview['missing_fields']))<ul class="mb-0">@foreach ($warrantyPreview['missing_fields'] as $missingField)<li>{{ $missingField }}</li>@endforeach</ul>@endif</div>
                    @elseif (blank($warrantyPreview['coverage'] ?? null) && blank($warrantyPreview['conditions'] ?? null))
                        <small class="text-muted d-block mt-50">{{ __('warranty.conditions_optional_notice') }}</small>
                    @endif
                    @if (filled($warrantyPreview['coverage'] ?? null) || filled($warrantyPreview['conditions'] ?? null) || filled($warrantyPreview['claim_instructions'] ?? null))
                        <details class="mt-1"><summary>{{ __('warranty.conditions_details') }}</summary>
                            @foreach (['coverage', 'conditions', 'claim_instructions'] as $termsField)
                                @if (filled($warrantyPreview[$termsField] ?? null))<div class="mt-1"><strong>{{ __('warranty.'.$termsField) }}</strong><div style="white-space: pre-line">{{ $warrantyPreview[$termsField] }}</div></div>@endif
                            @endforeach
                        </details>
                    @endif
                </div>
                <p class="text-muted">{{ __('warranty.draft_intro') }}</p>
                <form wire:submit="save">
                    <div class="row g-1">
                        <div class="col-md-6">
                            <label for="certificate-manual-term-value" class="form-label">{{ __('warranty.manual_term_value') }}</label>
                            <input id="certificate-manual-term-value" class="form-control" type="number" min="1" step="1" max="{{ match ($form['manual_term_unit'] ?? '') { 'years' => 100, 'months' => 1200, default => 36500 } }}" wire:model="form.manual_term_value">
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-manual-term-unit" class="form-label">{{ __('warranty.manual_term_unit') }}</label>
                            <select id="certificate-manual-term-unit" class="form-select" wire:model.live="form.manual_term_unit"><option value="">{{ __('warranty.choose_unit') }}</option>@foreach (['months', 'years', 'days'] as $unit)<option value="{{ $unit }}">{{ __('warranty.units.'.$unit) }}</option>@endforeach</select>
                        </div>
                        <div class="col-12"><small class="text-muted">{{ __('warranty.manual_term_notice') }}</small></div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('warranty.actual_supplier') }}</label>
                            <div class="form-control bg-light">{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($certificate->supplier) ?: __('warranty.supplier_missing') }}</div>
                            <small class="text-muted">{{ __('warranty.supplier_edit_in_delivery') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-native-sku" class="form-label">{{ __('warranty.native_supplier_sku') }}</label>
                            <input id="certificate-native-sku" class="form-control" type="text" maxlength="191" wire:model="form.supplier_native_sku">
                            <small class="text-muted d-block">{{ __('warranty.native_sku_help') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('warranty.supplier_system_reference') }}</label>
                            <div class="form-control bg-transparent">{{ $supplierIdentity['system_reference'] ?? '—' }}</div>
                            <small class="text-muted d-block">{{ __('warranty.supplier_system_reference_help') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-serial" class="form-label">{{ __('warranty.serial_number') }}</label>
                            <input id="certificate-serial" class="form-control" type="text" maxlength="190" wire:model="form.serial_number" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-no-serial" class="form-label">{{ __('warranty.not_serialized_reason') }}</label>
                            <input id="certificate-no-serial" class="form-control" type="text" maxlength="1000" wire:model="form.not_serialized_reason">
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-sale-date" class="form-label">{{ __('warranty.sale_date') }}</label>
                            <input id="certificate-sale-date" class="form-control" type="date" max="{{ now()->toDateString() }}" wire:model="form.sale_date">
                            <small class="text-muted d-block">{{ __('warranty.sale_date_automatic_help') }}</small>
                            <small class="text-muted d-block">{{ __('warranty.sale_date_source') }}: {{ $snapshot['dates']['sale_date_source_label'] ?? '—' }}</small>
                            @if ($certificate->sale_date_source_event_id)<small class="text-muted d-block">{{ __('warranty.sale_date_source_event') }} #{{ $certificate->sale_date_source_event_id }}</small>@endif
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-delivery-date" class="form-label">{{ __('warranty.actual_delivery_date') }}</label>
                            <input id="certificate-delivery-date" class="form-control" type="date" max="{{ now()->toDateString() }}" wire:model="form.delivery_date">
                        </div>
                        <div class="col-12">
                            <label for="certificate-sale-date-reason" class="form-label">{{ __('warranty.sale_date_override_reason') }}</label>
                            <textarea id="certificate-sale-date-reason" class="form-control" rows="2" maxlength="2000" wire:model="form.sale_date_override_reason"></textarea>
                            <small class="text-muted">{{ __('warranty.sale_date_override_help') }}</small>
                        </div>
                        <div class="col-12">
                            <div class="form-check"><input id="certificate-delivery-confirmed" class="form-check-input" type="checkbox" wire:model="form.delivery_date_confirmed"><label for="certificate-delivery-confirmed" class="form-check-label">{{ __('warranty.actual_delivery_attestation') }}</label></div>
                            @if ($certificate->deliveryItem?->delivery?->delivered_at)
                                <small class="text-muted d-block mt-50">{{ __('warranty.recorded_delivery') }}: {{ $certificate->deliveryItem->delivery->delivered_at->format('d.m.Y H:i') }}</small>
                            @endif
                        </div>
                        <div class="col-12">
                            <label for="certificate-delivery-reason" class="form-label">{{ __('warranty.delivery_date_reason') }}</label>
                            <textarea id="certificate-delivery-reason" class="form-control" rows="2" maxlength="2000" wire:model="form.delivery_date_reason"></textarea>
                            <small class="text-muted">{{ __('warranty.delivery_date_explainer') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-manufacturer-date" class="form-label">{{ __('warranty.manufacturer_date') }}</label>
                            <input id="certificate-manufacturer-date" class="form-control" type="date" max="{{ now()->toDateString() }}" wire:model="form.manufacturer_date">
                        </div>
                        <div class="col-md-6">
                            <label for="certificate-manufacturer-proof" class="form-label">{{ __('warranty.manufacturer_date_proof') }}</label>
                            <input id="certificate-manufacturer-proof" class="form-control" type="text" maxlength="2000" wire:model="form.manufacturer_date_proof">
                        </div>
                        <div class="col-12"><details><summary>{{ __('warranty.approved_policy') }}</summary>
                            <label for="certificate-policy" class="form-label">{{ __('warranty.approved_policy') }}</label>
                            <select id="certificate-policy" class="form-select" wire:model.live="form.policy_id">
                                <option value="">{{ __('warranty.choose_policy') }}</option>
                                @foreach ($policies as $policy)
                                    <option value="{{ $policy->id }}">{{ $policy->name }} · {{ __('warranty.version') }} {{ $policy->version }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('warranty.policy_match_explainer') }}</small>
                        </details></div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <button type="submit" class="btn btn-outline-primary" wire:loading.attr="disabled">{{ __('warranty.save_draft') }}</button>
                        @if ($isOwner || ! $certificate->previous_certificate_id)
                            <button type="button" class="btn btn-primary" wire:click="prepareAndSend" wire:loading.attr="disabled" @disabled(! $smsEnabled)>{{ __('warranty.prepare_and_send') }}</button>
                            <button type="button" class="btn btn-outline-success" wire:click="issue" wire:confirm="{{ __('warranty.issue_confirm') }}" wire:loading.attr="disabled">{{ __('warranty.prepare_certificate') }}</button>
                        @endif
                    </div>
                    <p class="text-muted mt-1 mb-0">{{ __('warranty.issue_explainer') }}</p>
                    @if (! $isOwner && $certificate->previous_certificate_id)<p class="text-muted mt-1 mb-0">{{ __('warranty.owner_correction_issue_required') }}</p>@endif
                </form>
            @else
                <div class="row g-1">
                    <div class="col-md-4"><small class="text-muted d-block">{{ __('warranty.serial_number') }}</small><strong>{{ ($snapshot['item']['serial_number'] ?? '') ?: ($snapshot['item']['not_serialized_reason'] ?? '—') }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">{{ __('warranty.sale_date') }}</small><strong>{{ $snapshot['dates']['sale_date'] ?? '—' }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">{{ __('warranty.actual_delivery_date') }}</small><strong>{{ $snapshot['dates']['delivery_date'] ?? '—' }}</strong></div>
                </div>
                <p class="text-muted mt-1 mb-0">{{ __('warranty.issued_immutable_notice') }}</p>
            @endif
        </div>
    </x-warranty.list-card>

    <x-warranty.list-card :title="__('warranty.original_documents')">
        <div class="card-body pt-0">
            @if ($blankForms->isNotEmpty())
                <h5>{{ __('warranty.roles.official_form') }}</h5>
                <ul class="list-unstyled">
                    @foreach ($blankForms as $document)
                        <li class="d-flex flex-wrap justify-content-between align-items-center gap-1 py-50"><span>{{ $document->label ?: $document->original_name }}</span><a href="{{ route('admin.warranties.document', $document) }}" class="btn btn-sm btn-outline-secondary">{{ __('warranty.download') }}</a></li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted">{{ __('warranty.completed_original_explainer') }}</p>
            <ul class="list-unstyled">
                @forelse ($certificate->documents as $document)
                    <li class="d-flex flex-wrap justify-content-between align-items-center gap-1 py-50"><span>{{ $document->label ?: $document->original_name }} <small class="text-muted">{{ __('warranty.roles.'.$document->role) }}</small></span><a href="{{ route('admin.warranties.document', $document) }}" class="btn btn-sm btn-outline-secondary">{{ __('warranty.download') }}</a></li>
                @empty
                    <li class="text-muted">{{ __('warranty.no_documents') }}</li>
                @endforelse
            </ul>
            @if ($isDraft)
                <form wire:submit="uploadCompletedOriginal">
                    <div class="row g-1 align-items-end">
                        <div class="col-md-9"><label for="completed-warranty-original" class="form-label">{{ __('warranty.completed_original') }}</label><input id="completed-warranty-original" class="form-control" type="file" accept="application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" wire:model="completedOriginal"></div>
                        <div class="col-md-3"><button type="submit" class="btn btn-outline-primary" wire:loading.attr="disabled" wire:target="completedOriginal,uploadCompletedOriginal">{{ __('warranty.upload_document') }}</button></div>
                    </div>
                    <div class="text-muted mt-50" wire:loading wire:target="completedOriginal">{{ __('warranty.uploading') }}</div>
                </form>
            @endif
        </div>
    </x-warranty.list-card>

    @if (! $isDraft)
        <x-warranty.list-card :title="__('warranty.frozen_terms')">
            <div class="card-body pt-0">
                <div class="row g-1">
                    @foreach (['issuer_name', 'term_text', 'territory', 'starts_on', 'ends_on'] as $field)
                        <div class="col-md-6"><small class="text-muted d-block">{{ __('warranty.fields.'.$field) }}</small><span>{{ $snapshot['warranty'][$field] ?? '—' }}</span></div>
                    @endforeach
                </div>
                @foreach (['coverage', 'component_terms', 'conditions', 'claim_instructions'] as $field)
                    @if (! empty($snapshot['warranty'][$field]))
                        <h6 class="mt-2">{{ __('warranty.fields.'.$field) }}</h6><p class="mb-0" style="white-space:pre-line">{{ $snapshot['warranty'][$field] }}</p>
                    @endif
                @endforeach
                <h6 class="mt-2">{{ __('warranty.service') }}</h6>
                <p class="mb-0">{{ $snapshot['warranty']['service']['name'] ?? '—' }} · {{ $snapshot['warranty']['service']['phone'] ?? '' }}<br>{{ $snapshot['warranty']['service']['address'] ?? '' }}<br>{{ $snapshot['warranty']['service']['email'] ?? '' }}</p>
            </div>
        </x-warranty.list-card>
    @endif

    @if ($certificate->status === 'issued')
        <x-warranty.list-card :title="__('warranty.send_customer')">
            <div class="card-body pt-0">
                <p>{{ __('warranty.sms_recipient_explainer') }} <strong>{{ $certificate->customer_phone }}</strong></p>
                @if ($smsEnabled)
                    <form method="POST" action="{{ route('admin.warranties.sms', $certificate) }}" x-data x-on:submit="if (!confirm(@js(__('warranty.sms_confirm')))) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ $smsActionKey }}">
                        @if ($smsAttempt)
                            <input type="hidden" name="previous_attempt_id" value="{{ $smsAttempt->id }}">
                            <p class="text-muted">{{ __('warranty.sms_result.'.$smsAttempt->status) }}</p>
                        @endif
                        <button type="submit" class="btn btn-primary" @disabled($smsAttempt && ! $smsResendState['canResend'])>{{ __($smsAttempt ? 'warranty.sms.resend' : 'warranty.send_sms') }}</button>
                        @if ($smsAttempt)
                            <p class="text-muted mt-1">{{ $smsResendState['retryAfterSeconds'] > 0 ? __('warranty.sms.resend_wait', ['seconds' => $smsResendState['retryAfterSeconds']]) : __('warranty.sms.resend_hint') }}</p>
                        @endif
                    </form>
                @else
                    <p class="text-muted mb-0">{{ __('warranty.sms_disabled') }}</p>
                @endif
            </div>
        </x-warranty.list-card>
    @endif

    @if ($isOwner && ! $isDraft)
        <x-warranty.list-card :title="__('warranty.owner_corrections')">
            <div class="card-body pt-0">
                <p class="text-muted">{{ __('warranty.correction_explainer') }}</p>
                <button type="button" class="btn btn-outline-primary mb-2" wire:click="correct" wire:confirm="{{ __('warranty.correct_confirm') }}" wire:loading.attr="disabled">{{ __('warranty.create_correction') }}</button>
                @if ($certificate->status === 'issued')
                    <form wire:submit="revoke">
                        <label for="certificate-revocation-reason" class="form-label">{{ __('warranty.revocation_reason') }}</label>
                        <textarea id="certificate-revocation-reason" class="form-control mb-1" maxlength="1000" rows="2" wire:model="revocationReason" required></textarea>
                        <button type="submit" class="btn btn-outline-danger" wire:confirm="{{ __('warranty.revoke_confirm') }}" wire:loading.attr="disabled">{{ __('warranty.revoke') }}</button>
                    </form>
                @endif
            </div>
        </x-warranty.list-card>
    @endif
    @if ($certificate->status === 'revoked')
        <div class="alert alert-danger" role="status"><strong>{{ __('warranty.statuses.revoked') }}</strong> · {{ $certificate->revocation_reason }}</div>
    @endif

    <x-warranty.list-card :title="__('warranty.history')">
        <div class="card-body pt-0">
            @if ($certificate->previous_certificate_id)<a href="{{ route('admin.warranties.show', ['certificate' => $certificate->previous_certificate_id]) }}" wire:navigate>{{ __('warranty.previous_revision') }}</a>@endif
            <ul class="list-unstyled mb-0">
                @foreach ($certificate->events->sortByDesc('id') as $event)
                    <li class="py-50"><time class="text-muted me-1">{{ $event->created_at?->format('d.m.Y H:i') }}</time>{{ __('warranty.events.'.$event->type) }} <small class="text-muted">{{ $event->actor?->name }}</small></li>
                @endforeach
            </ul>
        </div>
    </x-warranty.list-card>
</div>
