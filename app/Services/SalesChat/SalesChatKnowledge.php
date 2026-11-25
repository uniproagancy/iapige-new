<?php

namespace App\Services\SalesChat;

use App\Models\Setting;

class SalesChatKnowledge
{
    private const SETTING_KEY = 'sales_chat.knowledge';

    /** @return array{delivery_ka: string, delivery_en: string, facts: string} */
    public function snapshot(): array
    {
        $stored = Setting::query()->where('key', self::SETTING_KEY)->value('value');
        $knowledge = is_string($stored) ? json_decode($stored, true) : null;

        return [
            'delivery_ka' => $this->delivery($knowledge['delivery_ka'] ?? null, 'ka'),
            'delivery_en' => $this->delivery($knowledge['delivery_en'] ?? null, 'en'),
            'facts' => is_string($knowledge['facts'] ?? null) ? $knowledge['facts'] : '',
        ];
    }

    /** @return array{delivery: string, facts: string} */
    public function forAssistant(string $locale): array
    {
        $knowledge = $this->snapshot();

        return [
            'delivery' => $knowledge[$locale === 'ka' ? 'delivery_ka' : 'delivery_en'],
            'facts' => $knowledge['facts'],
        ];
    }

    public function save(string $deliveryKa, string $deliveryEn, ?string $facts): void
    {
        $value = json_encode([
            'delivery_ka' => trim($deliveryKa),
            'delivery_en' => trim($deliveryEn),
            'facts' => trim($facts ?? ''),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        Setting::query()->upsert([['key' => self::SETTING_KEY, 'value' => $value]], ['key'], ['value']);
        Setting::forgetCache();
    }

    private function delivery(mixed $value, string $locale): string
    {
        return is_string($value) && trim($value) !== '' ? $value : __('sales_chat_knowledge.default_delivery', [], $locale);
    }
}
