<?php

namespace App\Services\Warranties;

use App\Models\WarrantyCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyLink
{
    public function url(WarrantyCertificate $certificate): string
    {
        if (! config('warranty.enabled') || ! config('warranty.public_enabled')) {
            throw ValidationException::withMessages(['warranty' => __('warranty.errors.public_disabled')]);
        }
        $token = DB::transaction(function () use ($certificate): string {
            $current = WarrantyCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($current->status !== 'issued' || $current->revoked_at !== null) {
                throw ValidationException::withMessages(['warranty' => __('warranty.errors.not_issued')]);
            }
            if (! $current->access_token) {
                $secret = bin2hex(random_bytes(32));
                $current->forceFill([
                    'access_token' => $secret, 'access_token_hash' => hash('sha256', $secret),
                    'public_enabled_at' => now(),
                ])->save();
            }

            return $current->access_token;
        });

        // Use the configured shop origin, never a client-controlled Host header.
        $url = rtrim((string) config('app.url'), '/').route('warranty.public.show', ['token' => $token], false);
        if (! Str::startsWith($url, 'https://') && ! app()->environment('testing', 'local')) {
            throw ValidationException::withMessages(['warranty' => __('warranty.errors.https_required')]);
        }

        return $url;
    }

    public function find(string $token): WarrantyCertificate
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $token), 404);
        $certificate = WarrantyCertificate::query()->where('access_token_hash', hash('sha256', $token))->first();
        abort_unless($certificate && $certificate->status === 'issued'
            && $certificate->revoked_at === null && $certificate->public_enabled_at !== null, 404);

        return $certificate;
    }
}
