<div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
        <div><h3 class="mb-50">მომწოდებლების საგარანტიოები</h3><p class="text-muted mb-0">მონაცემები აქ ერთხელ ივსება და შესაბამის ტალონებს ავტომატურად მიჰყვება.</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('admin.warranties.index') }}" wire:navigate>{{ __('warranty.back_to_list') }}</a>
    </div>
    @if ($notice)<div class="alert alert-success" role="status">{{ $notice }}</div>@endif
    @if ($errors->any())<div class="alert alert-warning" role="alert"><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @if ($supplierCode === '')
        <div class="row g-1">
            @foreach ($suppliers as $supplier)
                <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0"><div class="card-body d-flex flex-column">
                    <h4>{{ $supplier['name'] }}</h4>
                    @if (filled($supplier['supplier_legal_name'] ?? null))<p class="small mb-50">{{ $supplier['supplier_legal_name'] }}</p>@endif
                    @if (($supplier['id'] ?? null) === 3 && $supplier['code'] === 'elite')<p class="small text-muted">მომწოდებლის სადილერო ფორმა</p>
                    @elseif (($supplier['id'] ?? null) === 1 && $supplier['code'] === 'zoommer')<p class="small text-muted">სადილერო ფორმა · ტელეფონი, პლანშეტი, TV, ლეპტოპი</p>
                    @elseif (($supplier['binding_missing'] ?? false) || ! ($supplier['id'] ?? null))<p class="small text-muted">შეკვეთებთან დაკავშირება მოგვიანებით დაემატება</p>
                    @else<p class="small text-muted">სერვისის კონტაქტები და საგარანტიო პირობები</p>@endif
                    <button class="btn btn-outline-primary mt-auto align-self-start" type="button" wire:click="selectSupplier('{{ $supplier['code'] }}')">გახსნა</button>
                </div></div></div>
            @endforeach
        </div>
    @else
        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" wire:click="selectSupplier('')">← ყველა მომწოდებელი</button>
        @foreach ($selected as $supplier)
            @php($review = $supplier['review'] ?? null)
            <h4 class="mb-1">{{ $supplier['name'] }}</h4>
            @if (filled($supplier['supplier_legal_name'] ?? null))<p class="mb-1"><span class="text-muted">მომწოდებლის იურიდიული დასახელება:</span> {{ $supplier['supplier_legal_name'] }}</p>@endif
            @if (($supplier['id'] ?? null) === 1 && $supplier['code'] === 'zoommer')<p class="small text-muted">ორიგინალი ბარათი ერთვის მხოლოდ ტელეფონს, პლანშეტს, ტელევიზორსა და ლეპტოპს.</p>@endif
            @if (($supplier['binding_missing'] ?? false) || ! ($supplier['id'] ?? null))<p class="text-muted">შეკვეთებთან ავტომატური დაკავშირება ჯერ არ აქვს. არსებული ინფორმაცია შეგიძლიათ ნახოთ.</p>@endif
            @if ($isOwner && is_int($supplier['id'] ?? null) && ! ($supplier['binding_missing'] ?? false))
                <button type="button" class="btn btn-primary mb-2" wire:click="startCustomRoute" wire:loading.attr="disabled">ბრენდის / პროდუქტის საგარანტიოს დამატება</button>
            @endif
            @if ($isOwner && $customOpen && $customSupplierId === ($supplier['id'] ?? null) && $customSupplierCode === $supplier['code'])
                <form class="card mb-2" wire:submit="createCustomRoute"><div class="card-body">
                    <h5>ახალი საგარანტიო მონაცემები</h5>
                    <p class="small text-muted">აირჩიეთ ბრენდი ან კონკრეტული პროდუქტი. უცნობი სერვისის მონაცემები შეგიძლიათ მოგვიანებით შეავსოთ; ვადა და IMEI შეკვეთაში იწერება.</p>
                    <div class="row g-1">
                        <div class="col-md-6"><label class="form-label" for="custom-warranty-scope">რომელ პროდუქტებზე?</label><select id="custom-warranty-scope" class="form-select" wire:model.live="scopeMode"><option value="brand">ბრენდი</option><option value="product">კონკრეტული პროდუქტი</option></select></div>
                        @if ($scopeMode === 'brand')
                            <div class="col-md-6"><label class="form-label" for="custom-warranty-brand">ბრენდი</label><select id="custom-warranty-brand" class="form-select" wire:model="customBrandId"><option value="">აირჩიეთ ბრენდი</option>@foreach ($customBrands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></div>
                            <div class="col-12"><label class="form-label" for="custom-warranty-category">კატეგორია — სურვილისამებრ</label><select id="custom-warranty-category" class="form-select" wire:model="customCategoryId"><option value="">ამ ბრენდის ყველა კატეგორია</option>@foreach ($customCategories as $category)<option value="{{ $category->id }}">{{ $category->name ?: 'უსახელო კატეგორია' }}</option>@endforeach</select></div>
                        @else
                            <div class="col-12">
                                <label class="form-label" for="custom-warranty-product-search">პროდუქტის მოძებნა</label>
                                <input id="custom-warranty-product-search" class="form-control" wire:model.live.debounce.300ms="productQuery" maxlength="120" placeholder="დასახელება ან საიტის SKU — მინიმუმ 2 სიმბოლო">
                                @if ($selectedCustomProduct)<p class="mt-50 mb-0"><strong>არჩეულია:</strong> {{ $selectedCustomProduct->name ?: 'უსახელო პროდუქტი' }}</p>@endif
                                @if ($customProducts->isNotEmpty())<div class="list-group mt-50">@foreach ($customProducts as $product)<button type="button" class="list-group-item list-group-item-action" wire:click="chooseCustomProduct({{ $product->id }})">{{ $product->name ?: 'უსახელო პროდუქტი' }}@if ($product->sku)<small class="text-muted d-block">საიტის SKU: {{ $product->sku }}</small>@endif</button>@endforeach</div>
                                @elseif (mb_strlen(trim($productQuery)) >= 2)<small class="text-muted d-block mt-50">პროდუქტი ვერ მოიძებნა. შეცვალეთ საძიებო სიტყვა.</small>@endif
                            </div>
                        @endif
                        @foreach (['service_name'=>'სერვისცენტრი','service_phone'=>'სერვისის ტელეფონი','service_address'=>'სერვისის მისამართი','service_email'=>'სერვისის ელფოსტა'] as $field=>$label)
                            <div class="col-md-6"><label class="form-label" for="custom-warranty-{{ $field }}">{{ $label }}</label><input id="custom-warranty-{{ $field }}" class="form-control" wire:model="customFields.{{ $field }}" maxlength="{{ $field === 'service_address' ? 2000 : 255 }}"></div>
                        @endforeach
                        @foreach (['coverage'=>'რას მოიცავს გარანტია','conditions'=>'საგარანტიო პირობები','claim_instructions'=>'როგორ მიმართოს კლიენტმა სერვისს'] as $field=>$label)
                            <div class="col-12"><label class="form-label" for="custom-warranty-{{ $field }}">{{ $label }}</label><textarea id="custom-warranty-{{ $field }}" class="form-control" rows="3" wire:model="customFields.{{ $field }}" maxlength="{{ $field === 'claim_instructions' ? 5000 : 10000 }}"></textarea></div>
                        @endforeach
                        <div class="col-12"><label class="form-label" for="custom-warranty-source">წყაროს ბმული ან შენიშვნა — არასავალდებულო</label><textarea id="custom-warranty-source" class="form-control" rows="2" wire:model="customFields.source_text" maxlength="3000"></textarea></div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-1"><button class="btn btn-primary" type="submit" wire:loading.attr="disabled">დამატება</button><button class="btn btn-outline-secondary" type="button" wire:click="cancelCustomRoute">დახურვა</button></div>
                </div></form>
            @endif
            @foreach ($supplier['groups'] as $group)
                @php($official = $group['official_terms'] ?? null)
                <div class="card mb-1"><div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-1">
                        <div>@if ($group['_custom'] ?? false)<span class="badge badge-light-{{ ($group['_custom_active'] ?? false) ? 'info' : 'secondary' }} mb-50">{{ ($group['_custom_active'] ?? false) ? 'ჩართულია' : 'გამორთულია' }}</span>@endif<small class="text-muted d-block">სერვისცენტრი</small><h5 class="mb-50">{{ $group['service_name'] ?: 'სერვისი დასამატებელია' }}</h5><p class="small text-muted mb-50">{{ $group['scope_label_ka'] }}</p>
                            <p class="mb-50">{{ $group['service_phone'] ?: 'ტელეფონი არ არის მითითებული' }}@if ($group['service_email']) · {{ $group['service_email'] }}@endif</p>
                            <p class="small mb-0">{{ $group['service_address'] ?: 'მისამართი მოგვიანებით შეგიძლიათ დაამატოთ' }}</p>
                        </div>
                        <div class="d-flex flex-wrap gap-50">
                            @if (! ($group['_custom'] ?? false) || ($group['_custom_active'] ?? false))<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.warranties.service-template', ['supplierCode'=>$supplier['code'], 'routeKey'=>$group['key']]) }}" target="_blank" rel="noopener noreferrer">ტალონის ნიმუში</a>@endif
                            @if ($isOwner && is_int($supplier['id'] ?? null) && ! ($supplier['binding_missing'] ?? false))<button class="btn btn-sm btn-primary" type="button" wire:click="edit('{{ $group['key'] }}')">რედაქტირება</button>@endif
                            @if ($isOwner && ($group['_custom'] ?? false))<button class="btn btn-sm btn-outline-{{ ($group['_custom_active'] ?? false) ? 'secondary' : 'primary' }}" type="button" wire:click="setCustomRouteActive('{{ $group['key'] }}', {{ ($group['_custom_active'] ?? false) ? 'false' : 'true' }})" wire:loading.attr="disabled">{{ ($group['_custom_active'] ?? false) ? 'გამორთვა' : 'ჩართვა' }}</button>@endif
                        </div>
                    </div>
                    @if ($editingSupplierId === ($supplier['id'] ?? null) && $editingSupplierCode === $supplier['code'] && $editingRouteKey === $group['key'])
                        <form class="border-top pt-1 mt-1" wire:submit="save">
                            <div class="row g-1">
                                <div class="col-12">
                                    <label class="form-label" for="supplier-profile-legal-name">მომწოდებლის იურიდიული დასახელება — არასავალდებულო</label>
                                    <input id="supplier-profile-legal-name" class="form-control" wire:model="form.supplier_legal_name" maxlength="255">
                                    <small class="text-muted d-block">ეს არის მომწოდებელი კომპანია; სერვისცენტრი ცალკე ველშია. დასახელება ამ მომწოდებლის ყველა მიმართულებაზე საერთოა.</small>
                                    @error('supplier_legal_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                </div>
                                @foreach (['service_name'=>'სერვისცენტრი','service_phone'=>'ტელეფონი','service_address'=>'მისამართი','service_email'=>'ელფოსტა'] as $field=>$label)
                                    <div class="col-md-6"><label class="form-label" for="supplier-profile-{{ $field }}">{{ $label }}</label><input id="supplier-profile-{{ $field }}" class="form-control" wire:model="form.{{ $field }}" maxlength="{{ $field === 'service_address' ? 2000 : 255 }}">@error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
                                @endforeach
                                @foreach (['coverage'=>'რას მოიცავს გარანტია','conditions'=>'საგარანტიო პირობები','claim_instructions'=>'როგორ მიმართოს კლიენტმა სერვისს'] as $field=>$label)
                                    <div class="col-12"><label class="form-label" for="supplier-profile-{{ $field }}">{{ $label }}</label><textarea id="supplier-profile-{{ $field }}" class="form-control" rows="4" wire:model="form.{{ $field }}" maxlength="{{ $field === 'claim_instructions' ? 5000 : 10000 }}"></textarea>@error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
                                @endforeach
                                <div class="col-12"><label class="form-label" for="supplier-profile-source">წყაროს ბმული ან შენიშვნა — არასავალდებულო</label><textarea id="supplier-profile-source" class="form-control" rows="2" wire:model="form.source_text" maxlength="3000"></textarea>@error('source_text')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
                            </div>
                            <p class="small text-muted mt-1">ვადა და სერიული ნომერი ივსება შეკვეთაში. უკვე გაცემული ტალონები არ შეიცვლება.</p>
                            @if ($group['_custom'] ?? false)<p class="small text-muted">ბრენდის ან პროდუქტის შესაცვლელად გამორთეთ ეს ჩანაწერი და დაამატეთ ახალი.</p>@endif
                            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">შენახვა</button><button class="btn btn-outline-secondary ms-50" type="button" wire:click="cancelEdit">დახურვა</button>
                        </form>
                    @else
                        <details class="mt-1"><summary>საგარანტიო პირობები</summary>
                            @if ($official && collect(['coverage','exclusions','customer_duties','claims','component_terms'])->contains(fn ($field) => ($official[$field] ?? []) !== []))
                                <p class="small text-muted mt-50">პირობები დამატებულია</p>
                                @foreach (['coverage'=>'დაფარვა','exclusions'=>'პირობები','customer_duties'=>'გამოყენება და მოვლა','claims'=>'მომსახურების მიღება','component_terms'=>'კომპონენტები'] as $field=>$label)
                                    @if (($official[$field] ?? []) !== [])<h6 class="mt-1 mb-50">{{ $label }}</h6>@foreach ($official[$field] as $line)<p class="small mb-50" style="white-space:pre-line">{{ $line }}</p>@endforeach @endif
                                @endforeach
                            @elseif ($group['conditions'])<p class="small mt-50" style="white-space:pre-line">{{ $group['conditions'] }}</p>
                            @else<p class="small text-muted mt-50">პირობები მოგვიანებით შეგიძლიათ დაამატოთ.</p>@endif
                        </details>
                    @endif
                </div></div>
            @endforeach
            <details class="small text-muted mb-2"><summary>წყაროები და დამატებითი ინფორმაცია</summary>
                @if ($review)
                    @if ($review['form_type'] === 'dealer_original')<p class="mt-1">მომწოდებლის ორიგინალი სადილერო ფორმა</p>@endif
                    <p>{{ $review['status_ka'] }}</p><p>{{ $review['scope_notice_ka'] }}</p>@if ($review['original_form_scope_ka'])<p>{{ $review['original_form_scope_ka'] }}</p>@endif
                    @foreach (array_merge($review['missing_fields'], $review['conflicts_ka']) as $line)<p>{{ $line }}</p>@endforeach
                @else<p class="mt-1">საგარანტიო ფორმის სტატუსი დაუზუსტებელია</p>@endif
                @foreach ($supplier['groups'] as $group)
                    @if (! $group['official_terms'])<p>ოფიციალური არსებითი საგარანტიო პირობები ჯერ დასამატებელია.</p>@endif
                    @foreach ($group['evidence'] ?? [] as $evidence)
                        @if (is_string($evidence['url'] ?? null) && parse_url($evidence['url'], PHP_URL_SCHEME) === 'https' && parse_url($evidence['url'], PHP_URL_USER) === null)<a class="d-block" href="{{ $evidence['url'] }}" target="_blank" rel="noopener noreferrer">{{ parse_url($evidence['url'], PHP_URL_HOST) }} · {{ $evidence['checked_on'] ?? '' }}</a>
                        @elseif (($evidence['kind'] ?? null) === 'owner_confirmation')<p>მფლობელის მიერ მოწოდებულია · {{ isset($evidence['date']) ? \Illuminate\Support\Carbon::parse($evidence['date'])->format('d.m.Y') : ($evidence['checked_on'] ?? '') }}</p>@endif
                    @endforeach
                    @if ($isOwner && ($group['_profile_source_text'] ?? false))<p style="white-space:pre-line">{{ $group['_profile_source_text'] }}</p>@endif
                @endforeach
            </details>
        @endforeach
    @endif
</div>
