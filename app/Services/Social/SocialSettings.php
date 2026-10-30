<?php

namespace App\Services\Social;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SocialSettings
{
    public const PAGE_ID = '103447072710661';

    public const INSTAGRAM_ID = '17841458783729265';

    public const CHANNELS = ['facebook_feed', 'instagram_feed', 'facebook_story', 'instagram_story'];

    public const DEFAULT_CHANNELS = ['facebook_feed' => true, 'instagram_feed' => false,
        'facebook_story' => false, 'instagram_story' => false];

    protected const REASONS = ['connection_missing', 'oauth_unavailable', 'wrong_page', 'permission_missing',
        'token_expired', 'connection_failed', 'connected'];

    public const CHANNEL_REASONS = ['not_checked', 'connected', 'connection_missing', 'permission_missing',
        'token_expired', 'connection_failed', 'wrong_page', 'instagram_not_linked', 'instagram_permission_missing',
        'instagram_account_unsupported', 'instagram_story_unsupported', 'instagram_unavailable',
        'account_not_supported', 'story_unsupported', 'rate_limited', 'instagram_missing', 'instagram_changed',
        'unsupported_account', 'quota_exceeded', 'media_unavailable', 'account_changed'];

    public function values(): array
    {
        $stored = Setting::stored();
        $theme = $stored['social.theme'] ?? 'auto';
        $slots = json_decode($stored['social.slots'] ?? 'null', true);
        $channels = [];
        $enabledAt = [];
        foreach (self::DEFAULT_CHANNELS as $channel => $default) {
            $key = 'social.channels.'.$channel;
            $channels[$channel] = array_key_exists($key, $stored)
                ? filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN) : $default;
            $timestamp = $stored['social.channel_enabled_at.'.$channel] ?? null;
            $enabledAt[$channel] = is_string($timestamp)
                && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $timestamp)
                ? $timestamp : null;
        }

        return ['enabled' => filter_var($stored['social.enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'page_id' => self::PAGE_ID, 'minimum_price' => 500, 'posts_per_day' => 5, 'cooldown_days' => 7,
            'theme' => in_array($theme, ['auto', 'evergreen', 'black_friday', 'new_year'], true) ? $theme : 'auto',
            'channels' => $channels,
            'channel_enabled_at' => $enabledAt,
            'slots' => $this->validSlots($slots) ? $slots : config('social.slots', ['10:00', '13:00', '16:00', '19:00', '21:00']),
            'artwork' => [
                'accent_color' => (string) ($stored['social.artwork.accent_color'] ?? ''),
                'headline' => (string) ($stored['social.artwork.headline'] ?? ''),
                'cta' => (string) ($stored['social.artwork.cta'] ?? ''),
                'tagline' => (string) ($stored['social.artwork.tagline'] ?? ''),
            ]];
    }

    public function save(array $values): void
    {
        $valid = Validator::make($values, [
            'enabled' => ['required', 'boolean'],
            'theme' => ['required', 'in:auto,evergreen,black_friday,new_year'],
            'slots' => ['required', 'array', 'size:5'],
            'slots.*' => ['required', 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', 'distinct'],
            'channels' => ['sometimes', 'array:facebook_feed,instagram_feed,facebook_story,instagram_story'],
            'channels.*' => ['boolean'],
            'artwork' => ['sometimes', 'array:accent_color,headline,cta,tagline'],
            'artwork.accent_color' => ['sometimes', 'nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/D'],
            'artwork.headline' => ['sometimes', 'nullable', 'string', 'max:70', 'not_regex:/[<>\x00-\x1f\x7f]/u'],
            'artwork.cta' => ['sometimes', 'nullable', 'string', 'max:28', 'not_regex:/[<>\x00-\x1f\x7f]/u'],
            'artwork.tagline' => ['sometimes', 'nullable', 'string', 'max:45', 'not_regex:/[<>\x00-\x1f\x7f]/u'],
        ])->validate();
        sort($valid['slots'], SORT_STRING);
        $changes = ['social.enabled' => $valid['enabled'] ? '1' : '0', 'social.theme' => $valid['theme'],
            'social.slots' => json_encode(array_values($valid['slots']), JSON_THROW_ON_ERROR)];
        foreach ($valid['artwork'] ?? [] as $key => $value) {
            $changes['social.artwork.'.$key] = $value === null ? '' : trim($value);
        }
        $previousChannels = $this->values()['channels'];
        foreach ($valid['channels'] ?? [] as $channel => $enabled) {
            $changes['social.channels.'.$channel] = $enabled ? '1' : '0';
            if ($enabled && ! $previousChannels[$channel]) {
                $changes['social.channel_enabled_at.'.$channel] = now('UTC')->toIso8601String();
            }
        }
        $this->put($changes);
    }

    public function status(): array
    {
        $stored = Setting::stored();
        $reason = $stored['social.connection_reason'] ?? 'connection_missing';
        $connected = ($stored['social.connection_ok'] ?? '0') === '1' && $this->token() !== null;
        if (! $connected && $reason === 'connected') {
            $reason = 'connection_missing';
        }
        $instagramId = $stored['social.instagram.id'] ?? null;
        $instagramId = is_string($instagramId) && preg_match('/\A[0-9]{1,32}\z/D', $instagramId) ? $instagramId : null;
        $instagramConnected = $connected && ($stored['social.instagram.connected'] ?? '0') === '1' && $instagramId !== null;
        $instagram = ['connected' => $instagramConnected, 'id' => $instagramId,
            'username' => $stored['social.instagram.username'] ?? null,
            'account_type' => $stored['social.instagram.account_type'] ?? null,
            'story_verified' => $this->storyVerified($stored),
            'reason' => $connected ? $this->channelReason($stored['social.instagram.reason'] ?? 'not_checked')
                : $this->channelReason($reason),
            'last_checked' => $stored['social.instagram.last_checked'] ?? null];
        $channelStatus = [];
        foreach (self::CHANNELS as $channel) {
            $baseConnected = str_starts_with($channel, 'facebook_') ? $connected : $instagramConnected;
            $prefix = 'social.channel_status.'.$channel.'.';
            $verified = array_key_exists($prefix.'connected', $stored)
                ? $stored[$prefix.'connected'] === '1' : ($channel === 'facebook_feed' && $connected);
            $channelStatus[$channel] = ['connected' => $baseConnected && $verified,
                'reason' => $baseConnected
                    ? $this->channelReason($stored[$prefix.'reason'] ?? ($channel === 'facebook_feed' ? $reason : 'not_checked'))
                    : $this->channelReason(str_starts_with($channel, 'facebook_') ? $reason : $instagram['reason']),
                'last_checked' => $stored[$prefix.'last_checked'] ?? null];
        }

        return ['connected' => $connected, 'page_name' => $stored['social.page_name'] ?? null,
            'last_checked' => $stored['social.last_checked'] ?? null,
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'connection_failed',
            'oauth_ready' => filled(config('services.facebook.client_id')) && filled(config('services.facebook.client_secret')),
            'instagram' => $instagram, 'channel_status' => $channelStatus];
    }

    public function recordInstagramStatus(bool $connected, string $reason, ?string $id = null, ?string $username = null, ?string $accountType = null): void
    {
        $validId = is_string($id) && preg_match('/\A[0-9]{1,32}\z/D', $id) === 1;
        $reason = $connected && ! $validId ? 'connection_failed' : $reason;
        $connected = $connected && $validId;
        $username = is_string($username) && preg_match('/\A[A-Za-z0-9._]{1,30}\z/D', $username) ? $username : '';
        $accountType = in_array($accountType, ['BUSINESS', 'MEDIA_CREATOR', 'CREATOR', 'PERSONAL'], true) ? $accountType : '';
        $changes = ['social.instagram.connected' => $connected ? '1' : '0',
            'social.instagram.reason' => $this->channelReason($connected ? 'connected' : $reason),
            'social.instagram.id' => $validId ? $id : '',
            'social.instagram.username' => $username,
            'social.instagram.account_type' => $accountType,
            'social.instagram.last_checked' => now()->toIso8601String()];
        if (($validId && $id !== self::INSTAGRAM_ID)
            || in_array($reason, ['instagram_changed', 'account_changed', 'wrong_page'], true)) {
            $changes['social.instagram.story_proof'] = '';
        }
        $this->put($changes);
    }

    public function recordChannelStatus(string $channel, bool $connected, string $reason): void
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('connection_failed');
        }
        $prefix = 'social.channel_status.'.$channel.'.';
        $changes = [$prefix.'connected' => $connected ? '1' : '0',
            $prefix.'reason' => $this->channelReason($connected ? 'connected' : $reason),
            $prefix.'last_checked' => now()->toIso8601String()];
        if ($channel === 'instagram_story' && $connected) {
            $token = $this->token();
            if ((Setting::stored()['social.instagram.id'] ?? null) !== self::INSTAGRAM_ID || $token === null) {
                $changes[$prefix.'connected'] = '0';
                $changes[$prefix.'reason'] = 'not_checked';
            } else {
                $changes['social.instagram.story_proof'] = json_encode([
                    'account_id' => self::INSTAGRAM_ID,
                    'credential' => $this->credentialFingerprint($token),
                    'verified_at' => now('UTC')->toIso8601String(),
                ], JSON_THROW_ON_ERROR);
            }
        }
        $this->put($changes);
    }

    protected function storyVerified(array $stored): bool
    {
        $proof = json_decode($stored['social.instagram.story_proof'] ?? 'null', true);
        $token = $this->token();

        return is_array($proof) && ($proof['account_id'] ?? null) === self::INSTAGRAM_ID
            && is_string($proof['credential'] ?? null) && $token !== null
            && hash_equals($proof['credential'], $this->credentialFingerprint($token));
    }

    protected function credentialFingerprint(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    protected function channelReason(string $reason): string
    {
        return in_array($reason, self::CHANNEL_REASONS, true) ? $reason : 'connection_failed';
    }

    /** Page publishing uses its own credential and never borrows the Pixel token. */
    public function token(): ?string
    {
        $cipher = Setting::stored()['social.page_token'] ?? null;
        if (is_string($cipher) && $cipher !== '') {
            try {
                $token = Crypt::decryptString($cipher);

                return $token !== '' ? $token : null;
            } catch (\Throwable) {
                return null;
            }
        }

        $token = config('social.page_access_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function storeConnection(string $token, string $pageId, string $name): void
    {
        if ($pageId !== self::PAGE_ID || $token === '' || strlen($token) > 16384 || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('wrong_page');
        }
        $changes = ['social.page_token' => Crypt::encryptString($token), 'social.page_name' => mb_substr($name, 0, 200),
            'social.connection_ok' => '1', 'social.connection_reason' => 'connected', 'social.last_checked' => now()->toIso8601String()];
        if ($this->token() !== $token) {
            $changes += ['social.instagram.connected' => '0', 'social.instagram.reason' => 'not_checked',
                'social.instagram.id' => '', 'social.instagram.username' => '', 'social.instagram.account_type' => '',
                'social.instagram.last_checked' => '', 'social.instagram.story_proof' => ''];
            foreach (self::CHANNELS as $channel) {
                $prefix = 'social.channel_status.'.$channel.'.';
                $changes[$prefix.'connected'] = $channel === 'facebook_feed' ? '1' : '0';
                $changes[$prefix.'reason'] = $channel === 'facebook_feed' ? 'connected' : 'not_checked';
                $changes[$prefix.'last_checked'] = $channel === 'facebook_feed' ? now()->toIso8601String() : '';
            }
        }
        $this->put($changes);
    }

    public function recordStatus(bool $connected, string $reason, ?string $name = null): void
    {
        $data = ['social.connection_ok' => $connected ? '1' : '0',
            'social.connection_reason' => in_array($reason, self::REASONS, true) ? $reason : 'connection_failed',
            'social.last_checked' => now()->toIso8601String()];
        if ($connected && $name !== null) {
            $data['social.page_name'] = mb_substr($name, 0, 200);
        }
        $this->put($data);
    }

    protected function put(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });
        Setting::forgetCache();
    }

    protected function validSlots(mixed $slots): bool
    {
        return is_array($slots) && array_is_list($slots) && count($slots) === 5
            && count(array_unique($slots, SORT_REGULAR)) === 5
            && count(array_filter($slots, fn ($value) => is_string($value) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $value))) === 5;
    }
}
