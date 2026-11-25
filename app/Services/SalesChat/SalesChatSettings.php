<?php

namespace App\Services\SalesChat;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SalesChatSettings
{
    public function enabled(): bool
    {
        return (bool) ($this->record()?->enabled ?? config('sales-chat.enabled', false));
    }

    public function hasKey(): bool
    {
        return filled($this->record()?->openai_key) || filled(config('sales-chat.openai_key'));
    }

    public function key(): ?string
    {
        $encrypted = $this->record()?->openai_key;

        if (filled($encrypted)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                return null;
            }
        }

        $fallback = config('sales-chat.openai_key');

        return is_string($fallback) && filled($fallback) ? $fallback : null;
    }

    public function enable(string $key): void
    {
        DB::table('sales_chat_settings')->upsert([[
            'id' => 1,
            'enabled' => true,
            'openai_key' => Crypt::encryptString($key),
            'updated_at' => now(),
        ]], ['id'], ['enabled', 'openai_key', 'updated_at']);

        $this->request()?->attributes->remove(self::class);
    }

    public function disable(): void
    {
        DB::table('sales_chat_settings')->upsert([[
            'id' => 1,
            'enabled' => false,
            'updated_at' => now(),
        ]], ['id'], ['enabled', 'updated_at']);

        $this->request()?->attributes->remove(self::class);
    }

    private function record(): ?object
    {
        if (! config('sales-chat.managed', false)) {
            return null;
        }

        $attributes = $this->request()?->attributes;
        if ($attributes?->has(self::class)) {
            return $attributes->get(self::class);
        }

        $record = DB::table('sales_chat_settings')->where('id', 1)->first();
        $attributes?->set(self::class, $record);

        return $record;
    }

    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
