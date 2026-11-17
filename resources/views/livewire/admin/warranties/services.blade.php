<div>
    <div class="d-flex justify-content-between align-items-center gap-1 mb-2">
        <h3>მომწოდებლების საგარანტიო შაბლონები</h3>
        <a class="btn btn-outline-secondary" href="{{ route('admin.warranties.index') }}" wire:navigate>{{ __('warranty.back_to_list') }}</a>
    </div>
    <p class="text-muted">აქ წინასწარ ნახავთ მომწოდებლის ტალონის ნიმუშს — სერვისის კონტაქტებითა და მოძიებული პირობებით. შეკვეთის არსებობა ან ჩაბარება საჭირო არ არის. უცნობი მონაცემები ცარიელია და ქვემოთ ცალკეა მონიშნული.</p>
    <label for="directory-supplier" class="form-label">{{ __('warranty.supplier') }}</label>
    <select id="directory-supplier" class="form-select mb-2" wire:model.live="supplierCode">
        <option value="">{{ __('warranty.all_suppliers') }}</option>
        @foreach ($suppliers as $supplier)<option value="{{ $supplier['code'] }}">{{ $supplier['name'] }}</option>@endforeach
    </select>
    @foreach ($selected as $supplier)
        <x-warranty.list-card :title="$supplier['name']">
            @if (($supplier['binding_missing'] ?? false) === true)
                <p class="text-warning px-1 small">შეკვეთებთან ავტომატური მიბმა ჯერ არ აქვს — მომწოდებლის ჩანაწერი დასაზუსტებელია.</p>
            @endif
            <div class="card-body pt-0"><div class="row g-1">
                @foreach ($supplier['groups'] as $group)
                    @php
                        $official = is_array($group['official_terms'] ?? null) ? $group['official_terms'] : null;
                        $termSections = [
                            'coverage' => 'დაფარვა',
                            'exclusions' => 'გამონაკლისები',
                            'customer_duties' => 'გამოყენება, მონტაჟი და მოვლა',
                            'claims' => 'სერვისთან მიმართვა',
                            'component_terms' => 'კომპონენტები და დამატებითი დაფარვა',
                        ];
                        $missingFields = [];
                        if (! $group['service_address']) { $missingFields[] = 'სერვისის მიღების ზუსტი მისამართი'; }
                        if ($official) {
                            $missingFields = array_merge($missingFields, array_filter($official['missing_fields'] ?? [], 'is_string'));
                        } else {
                            $missingFields[] = 'ოფიციალური არსებითი საგარანტიო პირობები';
                        }
                        $missingFields = array_values(array_unique($missingFields));
                    @endphp
                    <div class="col-lg-6"><div class="border rounded p-1 h-100">
                        <h5>{{ $group['service_name'] ?: '—' }}</h5>
                        @if ($official)
                            <span class="badge {{ ($official['status'] ?? '') === 'substantive' ? 'badge-light-success' : 'badge-light-warning' }} mb-1">{{ ($official['status'] ?? '') === 'substantive' ? 'ოფიციალური პირობები მოძიებულია' : 'პირობები ნაწილობრივ მოძიებულია' }}</span>
                        @endif
                        <a class="btn btn-sm btn-outline-primary mb-1" href="{{ route('admin.warranties.service-template', ['supplierCode' => $supplier['code'], 'routeKey' => $group['key']]) }}" target="_blank" rel="noopener noreferrer">ტალონის ნიმუში</a>
                        <p class="small">{{ implode('; ', array_unique($group['scopes'])) }}</p>
                        <p><strong>{{ __('warranty.phone') }}:</strong> {{ $group['service_phone'] ?: '—' }}<br>
                            <strong>{{ __('warranty.address') }}:</strong> {{ $group['service_address'] ?: '—' }}</p>
                        @if ($group['service_email'])<p class="small"><!--email_off-->{{ $group['service_email'] }}<!--/email_off--></p>@endif
                        @if ($official)
                            @if (is_string($official['scope_notice'] ?? null) && trim($official['scope_notice']) !== '')<p class="small">{{ $official['scope_notice'] }}</p>@endif
                            @foreach ($termSections as $field => $label)
                                @if (is_array($official[$field] ?? null) && $official[$field] !== [])
                                    <h6 class="mt-1 mb-0">{{ $label }}</h6>
                                    <ul class="small mb-1">
                                        @foreach ($official[$field] as $line)
                                            @if (is_string($line))
                                                <li>{{ $line }}</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @endif
                            @endforeach
                        @elseif ($group['conditions_confirmed'] && $group['conditions'])
                            <p class="mb-0 small" style="white-space:pre-line"><strong>სერვისთან მიმართვის მოძიებული წესი:</strong><br>{{ $group['conditions'] }}</p>
                        @endif
                        @if ($missingFields !== [])
                            <p class="text-warning small mt-1 mb-0">დასამატებელია: {{ implode(', ', $missingFields) }}.</p>
                        @endif
                        @if ($group['status'] === 'conflicting')<span class="badge badge-light-warning mt-1">{{ __('warranty.contact_needs_confirmation') }}</span>@endif
                        @if ($isOwner && $group['evidence'])
                            <details class="mt-1"><summary class="small">{{ __('warranty.source_reference') }}</summary>
                                @foreach ($group['evidence'] as $evidence)
                                    @if (isset($evidence['url']) && str_starts_with($evidence['url'], 'https://'))
                                        <a class="d-block small" href="{{ $evidence['url'] }}" target="_blank" rel="noopener noreferrer">{{ parse_url($evidence['url'], PHP_URL_HOST) }} · {{ $evidence['date'] ?? $evidence['checked_on'] ?? '' }}</a>
                                    @elseif (in_array($evidence['kind'] ?? null, ['owner_confirmation', 'owner_confirmed'], true))
                                        <span class="d-block small">მფლობელის მიერ მოწოდებულია@if (is_string($evidence['date'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $evidence['date'])) · {{ substr($evidence['date'], 8, 2).'.'.substr($evidence['date'], 5, 2).'.'.substr($evidence['date'], 0, 4) }}@endif</span>
                                    @endif
                                @endforeach
                            </details>
                        @endif
                        @if ($isOwner && $official && ($official['sources'] ?? []) !== [])
                            <details class="mt-1"><summary class="small">ოფიციალური საგარანტიო პირობების წყაროები</summary>
                                @foreach ($official['sources'] as $source)
                                    @if (is_array($source) && is_string($source['url'] ?? null) && parse_url($source['url'], PHP_URL_SCHEME) === 'https')
                                        <a class="d-block small" href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['title'] ?? parse_url($source['url'], PHP_URL_HOST) }} · {{ $source['checked_on'] ?? '' }}</a>
                                    @endif
                                @endforeach
                            </details>
                        @endif
                    </div></div>
                @endforeach
            </div></div>
        </x-warranty.list-card>
    @endforeach
</div>
