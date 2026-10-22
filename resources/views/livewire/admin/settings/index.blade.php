@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'check' => '<polyline points="20 6 9 17 4 12"/>',
            'x'     => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'warn'  => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        ];

        return '<svg class="adm-ico '.$class.'" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $text = function (string $field, string $label, string $type = 'text', array $extra = []) {
        return compact('field', 'label', 'type') + $extra;
    };

    $groups = [
        __('admin.set_group_shop') => [
            $text('shop_phone', __('admin.set_shop_phone'), 'text', ['col' => 4]),
            $text('shop_email', __('admin.set_shop_email'), 'email', ['col' => 4]),
            $text('shop_delivery_days', __('admin.set_shop_delivery_days'), 'number', ['col' => 4]),
            $text('shop_instalment_months', __('admin.set_shop_instalment_months'), 'number', ['col' => 4]),
            $text('shop_invoice_valid_days', __('admin.set_shop_invoice_valid_days'), 'number', ['col' => 4]),
            $text('shop_min_order', __('admin.set_shop_min_order'), 'number', ['col' => 4]),
        ],
        __('admin.set_group_company') => [
            $text('shop_company_name', __('admin.set_shop_company_name'), 'text', ['col' => 6]),
            $text('shop_company_tax_id', __('admin.set_shop_company_tax_id'), 'text', ['col' => 6]),
            $text('shop_company_address', __('admin.set_shop_company_address'), 'text', ['col' => 12]),
            $text('shop_company_bank', __('admin.set_shop_company_bank'), 'text', ['col' => 4]),
            $text('shop_company_swift', __('admin.set_shop_company_swift'), 'text', ['col' => 4]),
            $text('shop_company_iban', __('admin.set_shop_company_iban'), 'text', ['col' => 4]),
            $text('shop_company_accounts', __('admin.set_shop_company_accounts'), 'textarea', [
                'col' => 12,
                'rows' => 4,
                'hint' => __('admin.set_accounts_hint'),
            ]),
        ],
        __('admin.set_group_contact') => [
            $text('shop_dial_scheme', __('admin.set_shop_dial_scheme'), 'select', [
                'col' => 4,
                'options' => ['tel' => 'tel', 'callto' => 'callto', 'sip' => 'sip', 'zoiper' => 'zoiper'],
                'hint' => __('admin.set_dial_hint'),
            ]),
            $text('shop_phone_country', __('admin.set_shop_phone_country'), 'text', ['col' => 4]),
            $text('smsoffice_sender', __('admin.set_smsoffice_sender'), 'text', ['col' => 4]),
            $text('smsoffice_redirect_to', __('admin.set_smsoffice_redirect_to'), 'text', [
                'col' => 6,
                'hint' => __('admin.set_redirect_hint'),
            ]),
        ],
    ];
@endphp

<div>
    @foreach ($groups as $heading => $fields)
        <x-admin.list-card :title="$heading">
            <x-slot:filters>
                @foreach ($fields as $field)
                    <div class="col-md-{{ $field['col'] }}">
                        <label class="form-label" for="s-{{ $field['field'] }}">{{ $field['label'] }}</label>

                        @if ($field['type'] === 'textarea')
                            <textarea id="s-{{ $field['field'] }}" rows="{{ $field['rows'] ?? 3 }}"
                                      class="form-control @error('values.'.$field['field']) is-invalid @enderror"
                                      wire:model="values.{{ $field['field'] }}"></textarea>
                        @elseif ($field['type'] === 'select')
                            <select id="s-{{ $field['field'] }}" class="form-select @error('values.'.$field['field']) is-invalid @enderror"
                                    wire:model="values.{{ $field['field'] }}">
                                @foreach ($field['options'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="{{ $field['type'] }}" id="s-{{ $field['field'] }}"
                                   class="form-control @error('values.'.$field['field']) is-invalid @enderror"
                                   wire:model="values.{{ $field['field'] }}">
                        @endif

                        @error('values.'.$field['field'])
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        @if ($field['hint'] ?? null)
                            <small class="text-muted">{{ $field['hint'] }}</small>
                        @endif
                    </div>
                @endforeach
            </x-slot:filters>
        </x-admin.list-card>
    @endforeach

    <x-admin.list-card :title="__('admin.set_group_sms')">
        <div class="card-body pt-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                <div>
                    <span class="d-block fw-bold">
                        {{ config('smsoffice.enabled') ? __('admin.sms_on') : __('admin.sms_off') }}
                    </span>
                    <small class="text-muted">
                        {{ $smsReady ? __('admin.sms_ready') : __('admin.sms_not_ready') }}
                    </small>
                </div>

                <button type="button"
                        @class(['btn', 'btn-outline-danger' => config('smsoffice.enabled'), 'btn-success' => ! config('smsoffice.enabled')])
                        wire:click="toggleSms">
                    {{ config('smsoffice.enabled') ? __('admin.hide') : __('admin.show') }}
                </button>
            </div>
        </div>
    </x-admin.list-card>

    <x-admin.list-card :title="__('admin.set_group_secrets')">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th style="width:160px">{{ __('admin.status') }}</th>
                        <th style="width:260px">.env</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($secrets as $secret)
                        <tr>
                            <td>{{ $secret['label'] }}</td>
                            <td>
                                @if ($secret['warn'] ?? false)
                                    <span class="badge rounded-pill badge-light-warning">
                                        {!! $icon('warn') !!} {{ __('admin.set_test_mode') }}
                                    </span>
                                @elseif ($secret['set'])
                                    <span class="badge rounded-pill badge-light-success">
                                        {!! $icon('check') !!} {{ __('admin.set_configured') }}
                                    </span>
                                @else
                                    <span class="badge rounded-pill badge-light-secondary">
                                        {!! $icon('x') !!} {{ __('admin.set_missing') }}
                                    </span>
                                @endif
                            </td>
                            <td><code>{{ $secret['where'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.list-card>

    <div class="d-flex gap-1">
        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
            {{ __('admin.save') }}
        </button>
    </div>
</div>
