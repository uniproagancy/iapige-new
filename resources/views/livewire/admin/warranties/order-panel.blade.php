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
                            <select class="form-select form-select-sm" aria-label="{{ __('warranty.approved_policy') }}" wire:model="policyChoices.{{ $key }}"><option value="">{{ __('warranty.choose_policy') }}</option>@foreach ($card['policies'] as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="open({{ $item->id }}, {{ $unit }})" wire:loading.attr="disabled">{{ $certificate && ! $isDraft ? 'ტალონის ნახვა' : 'მომზადება / რედაქტირება' }}</button>
                        @if ($certificate)
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.warranties.print', $certificate) }}" target="_blank" rel="noopener">{{ __('warranty.print') }}</a>
                            @if ($card['smsAttemptId'] ?? null)
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="resend({{ $certificate->id }}, {{ $card['smsAttemptId'] }})" wire:loading.attr="disabled" @disabled(! ($card['canResend'] ?? false))>{{ __('warranty.sms.resend') }}</button>
                            @else
                                <button type="button" class="btn btn-sm btn-primary" wire:click="send({{ $certificate->id }})" wire:loading.attr="disabled" title="{{ $certificate->status === 'issued' ? 'კლიენტის ნომერზე ტალონის ბმულის გაგზავნა' : 'ტალონის შევსებისა და დადასტურების შემდეგ' }}" @disabled(! $card['canSend'])>SMS გაგზავნა</button>
                            @endif
                            @if ($certificate->status === 'issued')
                                @if ($isOwner)<button type="button" class="btn btn-sm btn-outline-warning" wire:click="correct({{ $certificate->id }})" wire:loading.attr="disabled">{{ __('warranty.create_correction') }}</button>@endif
                            @endif
                        @endif
                    </div>
                </div>
                @if ($certificate)<p class="small text-muted mt-50 mb-0">SMS-ის მიმღები: {{ $certificate->customer_phone ?: $order->phone }}@if ($isDraft) · გაგზავნა შესაძლებელი იქნება შევსებული ტალონის დადასტურების შემდეგ.@endif</p>@endif
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
                    <form wire:submit="save" class="mt-1">
                        <div class="row g-1">
                            <div class="col-md-4"><label class="form-label" for="ow-term-{{ $key }}">{{ __('warranty.manual_term_value') }}</label><input id="ow-term-{{ $key }}" type="number" min="1" max="36500" class="form-control" wire:model="form.manual_term_value"></div>
                            <div class="col-md-4"><label class="form-label" for="ow-unit-{{ $key }}">{{ __('warranty.manual_term_unit') }}</label><select id="ow-unit-{{ $key }}" class="form-select" wire:model="form.manual_term_unit"><option value="">{{ __('warranty.choose_unit') }}</option>@foreach (['months', 'years', 'days'] as $termUnit)<option value="{{ $termUnit }}">{{ __('warranty.units.'.$termUnit) }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label" for="ow-serial-{{ $key }}">{{ __('warranty.serial_number') }}</label><input id="ow-serial-{{ $key }}" class="form-control" maxlength="191" wire:model="form.serial_number"></div>
                            <div class="col-12"><label class="form-label" for="ow-no-serial-{{ $key }}">სერიული ნომრის არქონის მიზეზი — მხოლოდ თუ ნომერი არ აქვს</label><input id="ow-no-serial-{{ $key }}" class="form-control" maxlength="1000" wire:model="form.not_serialized_reason"></div>
                            @if ($card['policyStale'] ?? false)
                                <div class="col-12"><div class="alert alert-warning mb-0">პირობები განახლდა — აირჩიეთ მოქმედი პირობა და გადაამოწმეთ.</div></div>
                            @endif
                            @if ($card['policies']->count() > 1 || ($card['policyStale'] ?? false))
                                <div class="col-12"><label class="form-label">{{ __('warranty.approved_policy') }}</label><select class="form-select" wire:model="form.policy_id"><option value="">{{ __('warranty.choose_policy') }}</option>@foreach ($card['policies'] as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select></div>
                            @elseif ($card['policies']->isEmpty())
                                <div class="col-12">
                                    @if ($card['autoReady'] ?? false)
                                        <div class="alert alert-success mb-0">ამ პროდუქტის დადასტურებული საგარანტიო პირობები მზადაა და ტალონის დადასტურებისას ავტომატურად გამოიყენება.</div>
                                    @else
                                        <div class="alert alert-warning mb-0">ამ ნივთის პირობები ჯერ დასადასტურებელია. @if ($isOwner)ქვემოთ შეგიძლიათ ნაპოვნი მონაცემების გადამოწმება, შევსება და დადასტურება.@else ადმინს შეუძლია პირობების დადასტურება ამავე ბარათიდან.@endif</div>
                                    @endif
                                </div>
                            @endif
                            <div class="col-12"><div class="form-check"><input id="ow-supplier-{{ $key }}" type="checkbox" class="form-check-input" wire:model="form.actual_supplier_confirmed"><label for="ow-supplier-{{ $key }}" class="form-check-label">{{ __('warranty.actual_supplier_attestation') }}</label></div></div>
                        </div>
                        <div class="rounded bg-light p-1 my-1"><strong>{{ __('warranty.service') }}:</strong> {{ data_get($snapshot, 'warranty.service.name') ?: '—' }}<span class="d-block">{{ data_get($snapshot, 'warranty.service.phone') ?: '—' }} · {{ data_get($snapshot, 'warranty.service.address') ?: '—' }}</span></div>
                        @if ($isOwner)
                            <div class="border rounded p-1 mb-1">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-1">
                                    <strong>მომწოდებლის საგარანტიო პირობები</strong>
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="reviewConditions" wire:loading.attr="disabled">პირობების გადამოწმება / დადასტურება</button>
                                </div>
                                @if ($approvalOpen && $approvalCertificateId === $certificate->id)
                                    <p class="small text-muted mt-1">მოძიებული ინფორმაცია წინასწარ არის შევსებული. გადაამოწმეთ სერვისი და პირობები; საჭიროებისამებრ შეასწორეთ. ეს მოქმედება SMS-ს არ აგზავნის.</p>
                                    @if ($approvalMissingFields !== [])
                                        <div class="alert alert-warning mt-1 mb-1"><strong>დასაზუსტებელია:</strong><ul class="mb-0">@foreach ($approvalMissingFields as $missing)<li>{{ $missing }}</li>@endforeach</ul></div>
                                    @endif
                                    <div class="row g-1">
                                        <div class="col-md-6"><label class="form-label" for="ow-service-name-{{ $key }}">სერვისცენტრი / საგარანტიო მიმართვა</label><input id="ow-service-name-{{ $key }}" class="form-control" maxlength="1000" wire:model="approvalForm.service_name"></div>
                                        <div class="col-md-6"><label class="form-label" for="ow-service-phone-{{ $key }}">სერვისის ტელეფონი</label><input id="ow-service-phone-{{ $key }}" class="form-control" maxlength="255" wire:model="approvalForm.service_phone"></div>
                                        <div class="col-md-6"><label class="form-label" for="ow-service-address-{{ $key }}">სერვისის მისამართი</label><input id="ow-service-address-{{ $key }}" class="form-control" maxlength="2000" wire:model="approvalForm.service_address"></div>
                                        <div class="col-md-6"><label class="form-label" for="ow-service-email-{{ $key }}">სერვისის ელფოსტა</label><input id="ow-service-email-{{ $key }}" type="email" class="form-control" maxlength="255" wire:model="approvalForm.service_email"></div>
                                        <div class="col-12"><label class="form-label" for="ow-coverage-{{ $key }}">რას მოიცავს გარანტია</label><textarea id="ow-coverage-{{ $key }}" class="form-control" rows="3" maxlength="20000" wire:model="approvalForm.coverage"></textarea></div>
                                        <div class="col-12"><label class="form-label" for="ow-conditions-{{ $key }}">პირობები და გამონაკლისები</label><textarea id="ow-conditions-{{ $key }}" class="form-control" rows="5" maxlength="20000" wire:model="approvalForm.conditions"></textarea></div>
                                        <div class="col-12"><label class="form-label" for="ow-claims-{{ $key }}">როგორ მიმართოს მომხმარებელმა სერვისს</label><textarea id="ow-claims-{{ $key }}" class="form-control" rows="3" maxlength="15000" wire:model="approvalForm.claim_instructions"></textarea></div>
                                        <div class="col-12"><label class="form-label" for="ow-components-{{ $key }}">ცალკეული ნაწილების დამატებითი პირობები</label><textarea id="ow-components-{{ $key }}" class="form-control" rows="2" maxlength="15000" wire:model="approvalForm.component_terms"></textarea></div>
                                        <div class="col-md-6"><label class="form-label" for="ow-start-basis-{{ $key }}">გარანტიის ათვლა</label><select id="ow-start-basis-{{ $key }}" class="form-select" wire:model="approvalForm.start_basis"><option value="">აირჩიეთ ათვლის საფუძველი</option><option value="sale_date">გაყიდვის თარიღიდან</option><option value="delivery_date">ჩაბარების თარიღიდან</option><option value="manufacturer_date">მწარმოებლის პირობით დადასტურებული თარიღიდან</option></select></div>
                                        <div class="col-md-6"><label class="form-label" for="ow-source-{{ $key }}">პირობების წყარო / დამატებითი ინფორმაცია</label><textarea id="ow-source-{{ $key }}" class="form-control" rows="2" maxlength="5000" wire:model="approvalForm.source_reference"></textarea></div>
                                        <div class="col-12"><div class="form-check"><input id="ow-original-required-{{ $key }}" type="checkbox" class="form-check-input" wire:model="approvalForm.requires_original"><label class="form-check-label" for="ow-original-required-{{ $key }}">ტალონის ორიგინალის ფაილის დართვა აუცილებელია</label></div></div>
                                        <div class="col-12"><label class="form-label" for="ow-approval-note-{{ $key }}">ადმინის შენიშვნა — რა გადაამოწმეთ ან რა ინფორმაცია დაემატება მოგვიანებით</label><textarea id="ow-approval-note-{{ $key }}" class="form-control" rows="2" maxlength="5000" wire:model="approvalForm.review_note"></textarea></div>
                                        <div class="col-12"><div class="form-check"><input id="ow-admin-approval-{{ $key }}" type="checkbox" class="form-check-input" wire:model="approvalForm.approve_as_admin"><label class="form-check-label" for="ow-admin-approval-{{ $key }}">როგორც ადმინი, ვადასტურებ ამ მონაცემებით საგარანტიო ტალონის გაცემას.</label></div></div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 mt-1"><button type="button" class="btn btn-success" wire:click="authorizeConditions" wire:loading.attr="disabled">პირობების დადასტურება</button><button type="button" class="btn btn-outline-secondary" wire:click="closeConditions">დაკეცვა</button></div>
                                @endif
                            </div>
                        @endif
                        <details class="mb-1"><summary>დამატებითი მონაცემები / თარიღები</summary>
                            <div class="row g-1 mt-50">
                                @foreach (['sale_date' => 'sale_date', 'delivery_date' => 'actual_delivery_date', 'manufacturer_date' => 'manufacturer_date'] as $field => $label)<div class="col-md-4"><label class="form-label" for="ow-{{ $field }}-{{ $key }}">{{ __('warranty.'.$label) }}</label><input id="ow-{{ $field }}-{{ $key }}" type="date" max="{{ now()->toDateString() }}" class="form-control" wire:model="form.{{ $field }}"></div>@endforeach
                                <div class="col-12"><small class="text-muted">{{ __('warranty.sale_date_automatic_help') }} {{ data_get($snapshot, 'dates.sale_date_source_label') }}</small></div>
                                @foreach (['sale_date_override_reason' => 'sale_date_override_reason', 'delivery_date_reason' => 'delivery_date_reason', 'manufacturer_date_proof' => 'manufacturer_date_proof', 'supplier_native_sku_reference' => 'native_sku_reference', 'supplier_native_sku_reason' => 'native_sku_reason'] as $field => $label)<div class="col-md-6"><label class="form-label" for="ow-{{ $field }}-{{ $key }}">{{ __('warranty.'.$label) }}</label><textarea id="ow-{{ $field }}-{{ $key }}" class="form-control" rows="2" maxlength="2000" wire:model="form.{{ $field }}"></textarea></div>@endforeach
                                <div class="col-md-6"><label class="form-label" for="ow-native-{{ $key }}">{{ __('warranty.native_supplier_sku') }}</label><input id="ow-native-{{ $key }}" class="form-control" maxlength="191" wire:model="form.supplier_native_sku"></div>
                                <div class="col-12"><div class="form-check"><input id="ow-delivery-{{ $key }}" type="checkbox" class="form-check-input" wire:model="form.delivery_date_confirmed"><label class="form-check-label" for="ow-delivery-{{ $key }}">{{ __('warranty.actual_delivery_attestation') }}</label></div></div>
                            </div>
                        </details>
                        <div class="d-flex flex-wrap gap-1"><button class="btn btn-outline-primary" type="submit" wire:loading.attr="disabled">მონახაზის შენახვა</button><button class="btn btn-success" type="button" wire:click="issue" wire:loading.attr="disabled">ტალონის დადასტურება</button>@if (blank($form['serial_number'] ?? null) && blank($form['not_serialized_reason'] ?? null) && ! data_get($card, 'releaseState.ready'))<button class="btn btn-outline-info" type="button" wire:click="releaseToCourier({{ $certificate->id }})" wire:loading.attr="disabled">{{ __('warranty.courier.serial_release') }}</button>@endif<button class="btn btn-outline-secondary" type="button" wire:click="close">დაკეცვა</button></div>
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
