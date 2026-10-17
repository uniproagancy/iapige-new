<?php

namespace App\Livewire\Admin\Settings;

use App\Models\Setting;
use App\Services\Sender\SmsOffice;
use Livewire\Component;

class Index extends Component
{
    public array $values = [];

    public function mount(): void
    {
        foreach (array_keys(Setting::MAP) as $key) {
            $this->values[$this->field($key)] = (string) config($key, '');
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.shop_phone' => ['required', 'string', 'max:40'],
            'values.shop_email' => ['required', 'email', 'max:120'],
            'values.shop_delivery_days' => ['required', 'integer', 'min:0', 'max:60'],
            'values.shop_instalment_months' => ['required', 'integer', 'min:1', 'max:60'],
            'values.shop_invoice_valid_days' => ['required', 'integer', 'min:1', 'max:60'],
            'values.shop_company_name' => ['nullable', 'string', 'max:160'],
            'values.shop_company_tax_id' => ['nullable', 'string', 'max:40'],
            'values.shop_company_address' => ['nullable', 'string', 'max:200'],
            'values.shop_company_bank' => ['nullable', 'string', 'max:120'],
            'values.shop_company_swift' => ['nullable', 'string', 'max:20'],
            'values.shop_company_iban' => ['nullable', 'string', 'max:40'],
            'values.shop_company_accounts' => ['nullable', 'string', 'max:2000'],
            'values.smsoffice_sender' => ['nullable', 'string', 'max:20'],
            'values.smsoffice_redirect_to' => ['nullable', 'string', 'max:40'],
            'values.shop_dial_scheme' => ['required', 'in:tel,callto,sip,zoiper'],
            'values.shop_phone_country' => ['required', 'string', 'max:5'],
        ], [], $this->labels());

        foreach (array_keys(Setting::MAP) as $key) {
            Setting::put($key, $this->values[$this->field($key)] ?? '');
        }

        Setting::forgetCache();
        Setting::apply();

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function toggleSms(): void
    {
        $on = ! (bool) config('smsoffice.enabled');

        Setting::put('smsoffice.enabled', $on);
        Setting::forgetCache();
        Setting::apply();

        $this->values['smsoffice_enabled'] = $on ? '1' : '0';

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function secrets(): array
    {
        return [
            [
                'label' => __('admin.sms_key'),
                'set' => filled(config('smsoffice.api_key')),
                'where' => 'SMSOFFICE_KEY',
            ],
            [
                'label' => __('admin.pixel_id'),
                'set' => filled(config('services.facebook.pixel_id')),
                'where' => 'FACEBOOK_PIXEL_ID',
            ],
            [
                'label' => __('admin.pixel_token'),
                'set' => filled(config('services.facebook.access_token')),
                'where' => 'FACEBOOK_ACCESS_TOKEN',
            ],
            [
                'label' => __('admin.pixel_test_code'),
                'set' => filled(config('services.facebook.test_event_code')),
                'where' => 'FACEBOOK_TEST_EVENT_CODE',
                'warn' => filled(config('services.facebook.test_event_code')),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.admin.settings.index', [
            'smsReady' => app(SmsOffice::class)->enabled(),
            'secrets' => $this->secrets(),
        ])->layout('layouts.admin', ['title' => __('admin.settings')]);
    }

    protected function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    protected function labels(): array
    {
        $labels = [];

        foreach (array_keys(Setting::MAP) as $key) {
            $labels['values.'.$this->field($key)] = __('admin.set_'.$this->field($key));
        }

        return $labels;
    }
}
