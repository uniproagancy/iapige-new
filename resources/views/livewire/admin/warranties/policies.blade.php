<div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
        <a href="{{ route('admin.warranties.index') }}" class="btn btn-outline-secondary" wire:navigate>{{ __('warranty.back_to_list') }}</a>
        <button type="button" class="btn btn-primary" wire:click="newPolicy">{{ __('warranty.new_policy') }}</button>
    </div>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <div class="row">
        <div class="col-xl-4">
            <x-warranty.list-card :title="__('warranty.policies_title')" :count="$policies->total()">
                <div class="card-body pt-0">
                    <label for="policy-filter" class="form-label">{{ __('warranty.supplier') }}</label>
                    <select id="policy-filter" class="form-select mb-1" wire:model.live="supplierFilter">
                        <option value="">{{ __('warranty.all_suppliers') }}</option>
                        @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($supplier) }}</option>@endforeach
                    </select>
                    <label for="policy-list-search" class="form-label">{{ __('warranty.policy_search') }}</label>
                    <input id="policy-list-search" class="form-control mb-1" type="search" maxlength="120" wire:model.live.debounce.350ms="policySearch" placeholder="{{ __('warranty.policy_search_placeholder') }}">
                    @if ($productFilter !== '')
                        <div class="d-flex justify-content-between align-items-center gap-1 mb-1"><span>{{ __('warranty.product_id') }}: {{ $productFilter }}</span><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearProductFilter">{{ __('warranty.clear_product_filter') }}</button></div>
                    @endif
                    <div class="list-group">
                        @forelse ($policies as $row)
                            <button type="button" class="list-group-item list-group-item-action {{ $policyId === $row->id ? 'active' : '' }}" wire:click="editPolicy({{ $row->id }})" wire:key="warranty-policy-{{ $row->id }}">
                                <span class="d-block fw-bold">{{ $row->name }}</span>
                                @if ($row->product_id)
                                    <span class="d-block">{{ $row->product?->name ?: __('warranty.product') }} · {{ __('warranty.product_id') }}: {{ $row->product_id }}</span>
                                    <small class="d-block">{{ __('warranty.product_sku') }}: {{ $row->product?->sku ?: '—' }} · {{ __('warranty.supplier_source_reference') }}: {{ $row->product?->offers->firstWhere('supplier_id', $row->supplier_id)?->external_id ?: '—' }}</small>
                                @endif
                                <small>{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($row->supplier) }} · {{ __('warranty.version') }} {{ $row->version }} · {{ __('warranty.policy_statuses.'.$row->status) }}</small>
                                <small class="d-block mt-50">{{ __('warranty.period_fact') }}: {{ $policySummaries[$row->id]['period'] }} · {{ __('warranty.service') }}: {{ $policySummaries[$row->id]['service'] }}</small>
                                @if ($row->status === 'draft')
                                    <small class="d-block mt-50">{{ count($policySummaries[$row->id]['missing']) ? __('warranty.missing_fields').': '.implode(', ', $policySummaries[$row->id]['missing']) : __('warranty.filled_pending_approval') }}</small>
                                @endif
                            </button>
                        @empty
                            <p class="text-muted">{{ __('warranty.no_policies') }}</p>
                        @endforelse
                    </div>
                    @if ($policies->hasPages())<div class="mt-1">{{ $policies->links() }}</div>@endif
                </div>
            </x-warranty.list-card>
        </div>

        <div class="col-xl-8">
            <x-warranty.list-card :title="$policy ? $policy->name : __('warranty.new_policy')">
                <div class="card-body pt-0">
                    @if ($policy)
                        <p><span class="badge badge-light-{{ $policyStatus === 'approved' ? 'success' : ($policyStatus === 'archived' ? 'secondary' : 'warning') }}">{{ __('warranty.policy_statuses.'.$policyStatus) }}</span> <span class="text-muted">{{ __('warranty.version') }} {{ $policy->version }}</span></p>
                    @endif
                    @if ($policyStatus !== 'draft')
                        <div class="alert alert-info" role="status">{{ __('warranty.policy_new_version_notice') }}</div>
                    @endif
                    @if ($policyStatus === 'draft' && $selectedSummary)
                        <div class="alert alert-warning" role="status">
                            <p>{{ __('warranty.draft_facts_notice') }}</p>
                            <p>{{ __('warranty.period_fact') }}: <strong>{{ $selectedSummary['period'] }}</strong> · {{ __('warranty.service') }}: <strong>{{ $selectedSummary['service'] }}</strong></p>
                            <p class="mb-0">{{ count($selectedSummary['missing']) ? __('warranty.missing_fields').': '.implode(', ', $selectedSummary['missing']) : __('warranty.filled_pending_approval') }}</p>
                        </div>
                    @endif
                    <p class="text-muted">{{ __('warranty.policy_intro') }}</p>
                    @if ($research)
                        <div class="alert alert-info" role="note">
                            <h5>{{ __('warranty.research_title') }} · {{ $research['name'] }}</h5>
                            <p>{{ $research['note_ka'] }}</p>
                            <p class="mb-50"><strong>{{ __('warranty.research_pending') }}</strong></p>
                            <small>{{ __('warranty.research_checked') }}: {{ $research['checked_on'] }}</small>
                            <ul class="mb-0 mt-50">
                                @foreach ($research['source_urls'] as $url)<li><a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ parse_url($url, PHP_URL_HOST) }}{{ parse_url($url, PHP_URL_PATH) }}</a></li>@endforeach
                            </ul>
                        </div>
                    @endif

                    <form wire:submit="saveDraft">
                        <h5 class="mb-1">{{ __('warranty.scope_title') }}</h5>
                        <div class="row g-1">
                            <div class="col-md-6">
                                <label for="policy-supplier" class="form-label">{{ __('warranty.supplier') }}</label>
                                <select id="policy-supplier" class="form-select" wire:model.live="form.supplier_id">
                                    <option value="">{{ __('warranty.choose_supplier') }}</option>
                                    @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($supplier) }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="policy-name" class="form-label">{{ __('warranty.policy_name') }}</label>
                                <input id="policy-name" class="form-control" type="text" maxlength="200" wire:model="form.name">
                            </div>
                            <div class="col-md-6">
                                <label for="policy-brand" class="form-label">{{ __('warranty.brand_optional') }}</label>
                                <select id="policy-brand" class="form-select" wire:model="form.brand_id">
                                    <option value="">{{ __('warranty.all_brands') }}</option>
                                    @foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="policy-category" class="form-label">{{ __('warranty.category_optional') }}</label>
                                <select id="policy-category" class="form-select" wire:model="form.category_id">
                                    <option value="">{{ __('warranty.all_categories') }}</option>
                                    @foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="policy-product-search" class="form-label">{{ __('warranty.product_optional') }}</label>
                                <input id="policy-product-search" type="search" maxlength="120" class="form-control" wire:model.live.debounce.350ms="productSearch" placeholder="{{ __('warranty.product_search_placeholder') }}" autocomplete="off">
                                @if ($selectedProduct)
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mt-1"><span><strong>{{ $selectedProduct->name }}</strong><small class="d-block text-muted">{{ __('warranty.product_id') }}: {{ $selectedProduct->id }} · {{ __('warranty.product_sku') }}: {{ $selectedProduct->sku }}</small></span><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearProduct">{{ __('warranty.clear_selection') }}</button></div>
                                @endif
                                @if ($productMatches->isNotEmpty())
                                    <div class="list-group mt-1">
                                        @foreach ($productMatches as $product)
                                            <button type="button" class="list-group-item list-group-item-action" wire:click="selectProduct({{ $product->id }})" wire:key="policy-product-{{ $product->id }}">{{ $product->name }} <small>{{ $product->sku }}</small></button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        <p class="text-muted mt-1">{{ __('warranty.scope_explainer') }}</p>

                        <hr class="my-2">
                        <h5>{{ __('warranty.coverage_title') }}</h5>
                        <div class="row g-1">
                            <div class="col-sm-4"><label for="policy-term-value" class="form-label">{{ __('warranty.term_value') }}</label><input id="policy-term-value" class="form-control" type="number" min="1" step="1" wire:model="form.term_value"></div>
                            <div class="col-sm-4"><label for="policy-term-unit" class="form-label">{{ __('warranty.term_unit') }}</label><select id="policy-term-unit" class="form-select" wire:model="form.term_unit"><option value="">{{ __('warranty.choose_unit') }}</option>@foreach (['days', 'months', 'years'] as $unit)<option value="{{ $unit }}">{{ __('warranty.units.'.$unit) }}</option>@endforeach</select></div>
                            <div class="col-sm-4"><label for="policy-start-basis" class="form-label">{{ __('warranty.start_basis') }}</label><select id="policy-start-basis" class="form-select" wire:model="form.start_basis"><option value="">{{ __('warranty.choose_start_basis') }}</option>@foreach (['sale_date', 'delivery_date', 'manufacturer_date'] as $basis)<option value="{{ $basis }}">{{ __('warranty.start_bases.'.$basis) }}</option>@endforeach</select></div>
                            <div class="col-12"><label for="policy-term-text" class="form-label">{{ __('warranty.term_explanation') }}</label><input id="policy-term-text" class="form-control" type="text" maxlength="500" wire:model="form.term_text"></div>
                            <div class="col-12"><small class="text-muted">{{ __('warranty.policy_period_reference_help') }}</small></div>
                            @foreach (['coverage', 'component_terms', 'conditions', 'claim_instructions'] as $field)
                                <div class="col-12"><label for="policy-{{ $field }}" class="form-label">{{ __('warranty.fields.'.$field) }}</label><textarea id="policy-{{ $field }}" class="form-control" rows="3" maxlength="20000" wire:model="form.{{ $field }}"></textarea></div>
                            @endforeach
                        </div>

                        <hr class="my-2">
                        <h5>{{ __('warranty.issuer_service_title') }}</h5>
                        <div class="row g-1">
                            @foreach (['issuer_name', 'issuer_address', 'issuer_contact', 'territory', 'service_name', 'service_address', 'service_phone', 'service_email'] as $field)
                                <div class="col-md-6"><label for="policy-{{ $field }}" class="form-label">{{ __('warranty.fields.'.$field) }}</label><input id="policy-{{ $field }}" class="form-control" type="{{ $field === 'service_email' ? 'email' : 'text' }}" maxlength="500" wire:model="form.{{ $field }}"></div>
                            @endforeach
                        </div>

                        <hr class="my-2">
                        <h5>{{ __('warranty.source_title') }}</h5>
                        <div class="row g-1">
                            <div class="col-12"><label for="policy-source-url" class="form-label">{{ __('warranty.source_url') }}</label><input id="policy-source-url" class="form-control" type="url" maxlength="2000" wire:model="form.source_url"></div>
                            <div class="col-12"><label for="policy-source-reference" class="form-label">{{ __('warranty.source_reference') }}</label><textarea id="policy-source-reference" class="form-control" rows="2" maxlength="5000" wire:model="form.source_reference"></textarea></div>
                            <div class="col-12"><label for="policy-customer-reference" class="form-label">{{ __('warranty.customer_reference') }}</label><textarea id="policy-customer-reference" class="form-control" rows="2" maxlength="1000" wire:model="form.customer_reference">{{ $form['customer_reference'] ?? '' }}</textarea><small class="text-muted">{{ __('warranty.customer_reference_notice') }}</small></div>
                            <div class="col-12"><label for="policy-review-notes" class="form-label">{{ __('warranty.review_notes') }}</label><textarea id="policy-review-notes" class="form-control" rows="3" maxlength="20000" wire:model="form.review_notes">{{ $form['review_notes'] ?? '' }}</textarea><small class="text-muted">{{ __('warranty.review_notes_notice') }}</small></div>
                            <div class="col-md-6"><label for="policy-valid-from" class="form-label">{{ __('warranty.valid_from') }}</label><input id="policy-valid-from" class="form-control" type="date" wire:model="form.valid_from"></div>
                            <div class="col-md-6"><label for="policy-valid-until" class="form-label">{{ __('warranty.valid_until') }}</label><input id="policy-valid-until" class="form-control" type="date" wire:model="form.valid_until"></div>
                            <div class="col-12"><div class="form-check"><input id="policy-source-confirmed" class="form-check-input" type="checkbox" wire:model="form.source_confirmed"><label for="policy-source-confirmed" class="form-check-label">{{ __('warranty.source_confirmed_attestation') }}</label></div></div>
                            <div class="col-12"><div class="form-check"><input id="policy-original-required" class="form-check-input" type="checkbox" wire:model.live="form.requires_original"><label for="policy-original-required" class="form-check-label">{{ __('warranty.original_required') }}</label></div></div>
                            @if (empty($form['requires_original']))
                                <div class="col-12"><label for="policy-original-reason" class="form-label">{{ __('warranty.original_not_required_reason') }}</label><textarea id="policy-original-reason" class="form-control" rows="2" maxlength="5000" wire:model="form.original_not_required_reason"></textarea></div>
                            @endif
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $policyStatus === 'draft' ? __('warranty.save_draft') : __('warranty.save_new_version') }}</button>
                            @if ($policy && $policyStatus === 'draft')
                                <button type="button" class="btn btn-success" wire:click="approve" wire:confirm="{{ __('warranty.approve_confirm') }}" wire:loading.attr="disabled">{{ __('warranty.approve_policy') }}</button>
                            @endif
                            @if ($policy && $policyStatus !== 'archived')
                                <button type="button" class="btn btn-outline-secondary" wire:click="archive" wire:confirm="{{ __('warranty.archive_confirm') }}" wire:loading.attr="disabled">{{ __('warranty.archive_policy') }}</button>
                            @endif
                        </div>
                    </form>
                </div>
            </x-warranty.list-card>

            @if ($policy)
                <x-warranty.list-card :title="__('warranty.original_documents')">
                    <div class="card-body pt-0">
                        <p class="text-muted">{{ __('warranty.policy_documents_explainer') }}</p>
                        <ul class="list-unstyled">
                            @forelse ($policy->documents as $document)
                                <li class="d-flex flex-wrap justify-content-between align-items-center gap-1 py-50"><span>{{ $document->label ?: $document->original_name }} <small class="text-muted">{{ __('warranty.roles.'.$document->role) }}</small></span><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.warranties.document', $document) }}">{{ __('warranty.download') }}</a></li>
                            @empty
                                <li class="text-muted">{{ __('warranty.no_documents') }}</li>
                            @endforelse
                        </ul>
                        @if ($policyStatus === 'draft')
                            <form wire:submit="uploadDocument">
                                <div class="row g-1 align-items-end">
                                    <div class="col-md-5"><label for="policy-document-file" class="form-label">{{ __('warranty.document') }}</label><input id="policy-document-file" class="form-control" type="file" accept="application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" wire:model="documentFile"></div>
                                    <div class="col-md-4"><label for="policy-document-role" class="form-label">{{ __('warranty.document_role') }}</label><select id="policy-document-role" class="form-select" wire:model="documentRole">@foreach (['source', 'official_form', 'terms'] as $role)<option value="{{ $role }}">{{ __('warranty.roles.'.$role) }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><button type="submit" class="btn btn-outline-primary" wire:loading.attr="disabled" wire:target="documentFile,uploadDocument">{{ __('warranty.upload_document') }}</button></div>
                                </div>
                                <div class="text-muted mt-50" wire:loading wire:target="documentFile">{{ __('warranty.uploading') }}</div>
                            </form>
                        @endif
                    </div>
                </x-warranty.list-card>
            @endif
        </div>
    </div>
</div>
