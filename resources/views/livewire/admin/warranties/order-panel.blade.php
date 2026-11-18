<section class="card border mt-2" aria-label="საგარანტიო ტალონი" wire:poll.30s.visible>
    <div class="card-header"><div><h4 class="mb-50">საგარანტიო ტალონი</h4><small class="text-muted">{{ $order->name }} · {{ $order->phone }}</small></div></div>
    <div class="card-body pt-0">
        @if ($notice)<div class="alert alert-success" role="status">{{ $notice }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        @forelse ($cards as $card)
            @php
                $item = $card['item'];
                $certificate = $card['certificate'];
                $unit = $card['unit'];
                $key = $item->id.'_'.$unit;
                $isEditing = $editing && $certificate && $editing->id === $certificate->id;
                $isDraft = $certificate?->status === 'draft';
            @endphp
            <div class="border rounded p-1 mb-1" wire:key="order-warranty-{{ $key }}">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-1">
                    <div><strong>{{ $item->name }}</strong><small class="d-block text-muted">{{ __('warranty.unit_index') }} {{ $unit }} / {{ $item->qty }} · {{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($item->supplier) ?: '—' }}</small><small class="d-block">{{ __('warranty.supplier_sku') }}: {{ $card['native'] ?: '—' }}</small></div>
                    <div class="d-flex flex-wrap gap-50 align-items-center">
                        @if ($certificate)<span class="badge badge-light-{{ $certificate->status === 'issued' ? 'success' : ($certificate->status === 'revoked' ? 'danger' : 'warning') }}">{{ __('warranty.statuses.'.$certificate->status) }}</span>@endif
                        @if (! $certificate && $card['policies']->count() > 1)
                            <details><summary class="small">{{ __('warranty.approved_policy') }}</summary>
                                <select class="form-select form-select-sm" aria-label="{{ __('warranty.approved_policy') }}" wire:model="policyChoices.{{ $key }}"><option value="">{{ __('warranty.choose_policy') }}</option>@foreach ($card['policies'] as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select>
                            </details>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="open({{ $item->id }}, {{ $unit }})" wire:loading.attr="disabled">{{ $certificate && ! $isDraft ? 'ტალონის ნახვა' : 'მომზადება / რედაქტირება' }}</button>
                        @if ($certificate)
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.warranties.print', $certificate) }}" target="_blank" rel="noopener">{{ __('warranty.print') }}</a>
                            @if ($card['smsAttemptId'] ?? null)
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="resend({{ $certificate->id }}, {{ $card['smsAttemptId'] }})" wire:loading.attr="disabled" @disabled(! ($card['canResend'] ?? false))>{{ __('warranty.sms.resend') }}</button>
                            @else
                                <button type="button" class="btn btn-sm btn-primary" wire:click="send({{ $certificate->id }})" wire:loading.attr="disabled" title="{{ $certificate->status === 'issued' ? 'კლიენტის ნომერზე ტალონის ბმულის გაგზავნა' : 'შევსებული ტალონის მომზადების შემდეგ' }}" @disabled(! $card['canSend'])>SMS გაგზავნა</button>
                            @endif
                            @if ($certificate->status === 'issued')
                                @if ($isOwner)<button type="button" class="btn btn-sm btn-outline-warning" wire:click="correct({{ $certificate->id }})" wire:loading.attr="disabled">{{ __('warranty.create_correction') }}</button>@endif
                            @endif
                        @endif
                    </div>
                </div>
                @if ($certificate)<p class="small text-muted mt-50 mb-0">SMS-ის მიმღები: {{ $certificate->customer_phone ?: $order->phone }}@if ($isDraft) · შეავსეთ ტალონი და აირჩიეთ „{{ __('warranty.prepare_and_send') }}“.@endif</p>@endif
                @if (data_get($card, 'releaseState.ready'))<div class="alert alert-info py-50 mt-1 mb-0" role="status">{{ __('warranty.courier.serial_released') }}</div>@endif
                @if ($card['smsStatus'])<p class="small text-muted mt-50 mb-0" role="status">SMS: {{ __('warranty.sms_result.'.$card['smsStatus']) }}</p>@endif
                @if ($card['smsAttemptId'] ?? null)
                    <p class="small text-muted mt-50 mb-0">{{ __('warranty.sms.resend_hint') }}</p>
                    @if (data_get($card, 'resendState.reason') === 'sms_in_progress')
                        <p class="small text-muted mt-50 mb-0">{{ __('warranty.errors.sms_in_progress') }}</p>
                    @elseif ((int) data_get($card, 'resendState.retryAfterSeconds', 0) > 0)
                        <p class="small text-muted mt-50 mb-0">{{ __('warranty.sms.resend_wait', ['seconds' => (int) data_get($card, 'resendState.retryAfterSeconds')]) }}</p>
                    @endif
                @endif
                @if ($isEditing && $isDraft)
                    @if (data_get($snapshot, 'supplier_dealer_form.schema') === 'iapi.warranty.elite-dealer.v1')<div class="alert alert-info mt-1 mb-1">ამ ტალონს ერთვის „ელიტ ელექტრონიქსის“ ორიგინალი სადილერო საგარანტიო ფორმა — სრული პირობებითა და სერვისცენტრების მისამართებით. შეავსეთ ვადა და ნივთის სერიული ნომერი / IMEI; შეკვეთის მონაცემები ავტომატურად ჩაიწერება. ხელმოწერის ადგილები ცარიელი რჩება.</div>@endif
                    @if (data_get($snapshot, 'supplier_dealer_form.schema') === 'iapi.warranty.zoommer-dealer.v1')<div class="alert alert-info mt-1 mb-1">ამ ტალონს ერთვის ზუმერის სადილერო ბარათის პირობები — მობილურებისთვის, პლანშეტებისთვის, ტელევიზორებისა და ლეპტოპებისთვის. ტექნოლაინის მიმდინარე მისამართები უკვე მითითებულია. ვადა შეავსეთ ხელით; სერიული ნომერი / IMEI ცალკე შეიყვანეთ. მომხმარებლის ხელმოწერა ავტომატურად არ ივსება.</div>@endif
                    @if ($sourceStale)<div class="alert alert-warning mt-1" role="alert">მიწოდების ნივთის მომწოდებელი ან მისი მონაცემები შეიცვალა. ხელახლა გახსენით „მომზადება / რედაქტირება“, რომ განახლებული ინფორმაცია ჩაიტვირთოს. ტალონის მომზადება მანამდე შეჩერებულია.</div>@endif
                    <form wire:submit="save" class="mt-1">
                        <div class="row g-1">
                            <div class="col-12">
                                <label class="form-label" for="ow-actual-supplier-{{ $key }}">{{ __('warranty.supplier') }}</label>
                                <div class="d-flex flex-wrap gap-1"><select id="ow-actual-supplier-{{ $key }}" class="form-select" wire:model="selectedSupplierId" @disabled(! $canChangeSupplier || $sourceStale)><option value="">აირჩიეთ მომწოდებელი</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($supplier) ?: '—' }}</option>@endforeach</select><button class="btn btn-outline-primary" type="button" wire:click="changeSupplier" wire:loading.attr="disabled" @disabled(! $canChangeSupplier || $sourceStale)>მომწოდებლის შეცვლა</button></div>
                                <small class="text-muted d-block">შეცვლა ამ შეკვეთის მიწოდების ნივთზეც აისახება. შემდეგ გადაამოწმეთ ახალი მომწოდებლის კოდი და პირობები.</small>
                                @if (! $canChangeSupplier)<small class="text-muted d-block">ჩაბარებულ შეკვეთაზე ან გაცემული ტალონის მქონე ნივთზე მომწოდებლის შეცვლა შეუძლია ადმინს.</small>@endif
                                @error('selectedSupplierId')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4"><label class="form-label" for="ow-term-{{ $key }}">{{ __('warranty.manual_term_value') }}</label><input id="ow-term-{{ $key }}" type="number" min="1" max="36500" class="form-control" wire:model="form.manual_term_value"></div>
                            <div class="col-md-4"><label class="form-label" for="ow-unit-{{ $key }}">{{ __('warranty.manual_term_unit') }}</label><select id="ow-unit-{{ $key }}" class="form-select" wire:model="form.manual_term_unit"><option value="">{{ __('warranty.choose_unit') }}</option>@foreach (['months', 'years', 'days'] as $termUnit)<option value="{{ $termUnit }}">{{ __('warranty.units.'.$termUnit) }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label" for="ow-serial-{{ $key }}">{{ __('warranty.serial_number') }}</label><input id="ow-serial-{{ $key }}" class="form-control" maxlength="191" wire:model="form.serial_number"></div>
                            <div class="col-12">
                                <label class="form-label" for="ow-native-{{ $key }}">მომწოდებლის პროდუქტის კოდი (SKU / არტიკული)</label>
                                <input id="ow-native-{{ $key }}" class="form-control" maxlength="191" wire:model="form.supplier_native_sku" aria-describedby="ow-native-help-{{ $key }}">
                                <small id="ow-native-help-{{ $key }}" class="text-muted d-block">გამოიყენეთ მომწოდებლის პრაისში ან დოკუმენტში მითითებული კოდი. ჩვენი საიტის SKU და მოწყობილობის სერიული ნომერი / IMEI ცალკე მონაცემებია.</small>
                                @error('supplier_native_sku')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12"><label class="form-label" for="ow-no-serial-{{ $key }}">სერიული ნომრის არქონის მიზეზი — მხოლოდ თუ ნომერი არ აქვს</label><input id="ow-no-serial-{{ $key }}" class="form-control" maxlength="1000" wire:model="form.not_serialized_reason"></div>
                        </div>
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
                            @if ($isOwner && $item->supplier)
                                <a class="btn btn-sm btn-outline-primary mt-50" href="{{ route('admin.warranties.services', ['supplier' => $item->supplier->code]) }}" target="_blank" rel="noopener noreferrer">{{ __('warranty.edit_supplier_terms') }}</a>
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
                        <details class="mb-1" @if ($card['policyStale'] ?? false) open @endif><summary>დამატებითი მონაცემები / თარიღები</summary>
                            <div class="row g-1 mt-50">
                                @if ($card['policyStale'] ?? false)<div class="col-12"><div class="alert alert-warning mb-0">პირობები განახლდა — აირჩიეთ მოქმედი პირობა და გადაამოწმეთ.</div></div>@endif
                                @if ($card['policies']->count() > 1 || ($card['policyStale'] ?? false))
                                    <div class="col-12"><label class="form-label">{{ __('warranty.approved_policy') }}</label><select class="form-select" wire:model="form.policy_id"><option value="">{{ __('warranty.choose_policy') }}</option>@foreach ($card['policies'] as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select></div>
                                @endif
                                @foreach (['sale_date' => 'sale_date', 'delivery_date' => 'actual_delivery_date', 'manufacturer_date' => 'manufacturer_date'] as $field => $label)<div class="col-md-4"><label class="form-label" for="ow-{{ $field }}-{{ $key }}">{{ __('warranty.'.$label) }}</label><input id="ow-{{ $field }}-{{ $key }}" type="date" max="{{ now()->toDateString() }}" class="form-control" wire:model="form.{{ $field }}"></div>@endforeach
                                <div class="col-12"><small class="text-muted">{{ __('warranty.sale_date_automatic_help') }} {{ data_get($snapshot, 'dates.sale_date_source_label') }}</small></div>
                                @foreach (['sale_date_override_reason' => 'sale_date_override_reason', 'delivery_date_reason' => 'delivery_date_reason', 'manufacturer_date_proof' => 'manufacturer_date_proof'] as $field => $label)<div class="col-md-6"><label class="form-label" for="ow-{{ $field }}-{{ $key }}">{{ __('warranty.'.$label) }}</label><textarea id="ow-{{ $field }}-{{ $key }}" class="form-control" rows="2" maxlength="2000" wire:model="form.{{ $field }}"></textarea></div>@endforeach
                                <div class="col-12"><div class="form-check"><input id="ow-delivery-{{ $key }}" type="checkbox" class="form-check-input" wire:model="form.delivery_date_confirmed"><label class="form-check-label" for="ow-delivery-{{ $key }}">{{ __('warranty.actual_delivery_attestation') }}</label></div></div>
                            </div>
                        </details>
                        <div class="d-flex flex-wrap gap-1"><button class="btn btn-primary" type="button" wire:click="prepareAndSend" wire:loading.attr="disabled" @disabled($sourceStale || ! $smsEnabled || ! $gatewayReady)>{{ __('warranty.prepare_and_send') }}</button><button class="btn btn-outline-primary" type="submit" wire:loading.attr="disabled" @disabled($sourceStale)>{{ __('warranty.save_draft') }}</button><button class="btn btn-outline-success" type="button" wire:click="issue" wire:loading.attr="disabled" @disabled($sourceStale)>{{ __('warranty.prepare_certificate') }}</button>@if (blank($form['serial_number'] ?? null) && blank($form['not_serialized_reason'] ?? null) && ! data_get($card, 'releaseState.ready'))<button class="btn btn-outline-info" type="button" wire:click="releaseToCourier({{ $certificate->id }})" wire:loading.attr="disabled" @disabled($sourceStale)>{{ __('warranty.courier.serial_release') }}</button>@endif<button class="btn btn-outline-secondary" type="button" wire:click="close">დაკეცვა</button></div>
                        <p class="small text-muted mt-1 mb-0">{{ __('warranty.issue_explainer') }}</p>
                    </form>
                    @if ($editing->policy?->requires_original)
                        <div class="border-top mt-1 pt-1"><label class="form-label">{{ __('warranty.completed_original') }}</label><input type="file" class="form-control" wire:model="completedOriginal" accept=".pdf,.docx"><button type="button" class="btn btn-sm btn-outline-primary mt-50" wire:click="uploadCompletedOriginal" wire:loading.attr="disabled">{{ __('warranty.upload_document') }}</button></div>
                    @endif
                @elseif ($isEditing)
                    <p class="mt-1 mb-0">{{ __('warranty.manual_term_value') }}: {{ data_get($snapshot, 'warranty.term_text') ?: '—' }} · {{ __('warranty.serial_number') }}: {{ data_get($snapshot, 'item.serial_number') ?: '—' }}</p>
                @endif
            </div>
        @empty
            <p class="text-muted mb-0">შეკვეთის მიწოდების ნივთები ჯერ არ არის შექმნილი. აქ გამოჩნდება არსებული ნივთები და მათი მომწოდებლები.</p>
        @endforelse
        @if (! $smsEnabled)<p class="text-muted mb-0 mt-1">SMS გაგზავნა ჯერ გამორთულია. ტალონის მომზადება და შენახვა შეტყობინებას არ აგზავნის.</p>@endif
        @if ($smsEnabled && ! $gatewayReady)<p class="text-muted mb-0 mt-1">{{ __('warranty.errors.sms_gateway_disabled') }}</p>@endif
    </div>
</section>
