<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Setting;
use App\Services\Social\PostPlanner;
use App\Services\Social\SocialSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class SocialSettingsTest extends TestCase
{
    public function test_defaults_are_disabled_and_product_rules_and_page_cannot_be_overridden(): void
    {
        config(['social.page_id' => '999', 'social.minimum_price' => 1, 'social.cooldown_days' => 0]);
        foreach (['social.page_id' => '999', 'social.minimum_price' => '1', 'social.cooldown_days' => '0'] as $key => $value) {
            Setting::create(compact('key', 'value'));
        }
        $this->assertSame([
            'enabled' => false,
            'page_id' => '103447072710661',
            'minimum_price' => 500,
            'posts_per_day' => 5,
            'cooldown_days' => 7,
            'theme' => 'auto',
            'channels' => SocialSettings::DEFAULT_CHANNELS,
            'channel_enabled_at' => array_fill_keys(SocialSettings::CHANNELS, null),
            'slots' => PostPlanner::DEFAULT_SLOTS,
            'artwork' => ['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => ''],
        ], (new SocialSettings)->values());
    }

    public function test_page_token_is_encrypted_at_rest_and_never_returned_in_public_values_or_status(): void
    {
        $settings = new SocialSettings;
        $token = 'synthetic-dedicated-page-publishing-token';
        config(['services.facebook.client_id' => 'fixture-app', 'services.facebook.client_secret' => 'synthetic-oauth-secret']);
        $settings->storeConnection($token, SocialSettings::PAGE_ID, 'Fixture IAPI Page');
        $cipher = Setting::query()->where('key', 'social.page_token')->value('value');

        $this->assertNotSame($token, $cipher);
        $this->assertStringNotContainsString($token, $cipher);
        $this->assertSame($token, Crypt::decryptString($cipher));
        $this->assertSame($token, $settings->token());
        $this->assertSame(['enabled', 'page_id', 'minimum_price', 'posts_per_day', 'cooldown_days', 'theme', 'channels', 'channel_enabled_at', 'slots', 'artwork'], array_keys($settings->values()));
        $this->assertSame(['accent_color', 'headline', 'cta', 'tagline'], array_keys($settings->values()['artwork']));
        $this->assertSame(['connected', 'page_name', 'last_checked', 'reason', 'oauth_ready', 'instagram', 'channel_status'], array_keys($settings->status()));
        $public = json_encode([$settings->values(), $settings->status()], JSON_THROW_ON_ERROR);
        foreach ([$token, $cipher, 'synthetic-oauth-secret', 'page_token', 'page_access_token', 'client_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $public);
        }
        $this->assertTrue($settings->status()['connected']);
        $this->assertTrue($settings->status()['oauth_ready']);
    }

    public function test_pixel_credential_is_never_used_as_a_page_publishing_credential(): void
    {
        config([
            'services.facebook.access_token' => 'synthetic-pixel-only-token',
            'services.facebook.pixel_id' => '123456',
            'social.page_access_token' => null,
        ]);
        $settings = new SocialSettings;

        $this->assertNull($settings->token());
        $this->assertFalse($settings->status()['connected']);
        $this->assertStringNotContainsString('synthetic-pixel-only-token', json_encode([$settings->values(), $settings->status()]));
        config(['social.page_access_token' => ' synthetic-dedicated-env-page-token ']);
        $this->assertSame('synthetic-dedicated-env-page-token', $settings->token());
        $this->assertStringNotContainsString('synthetic-dedicated-env-page-token', json_encode([$settings->values(), $settings->status()]));
    }

    public function test_corrupt_stored_token_fails_closed_even_if_an_environment_token_exists(): void
    {
        config(['social.page_access_token' => 'synthetic-env-fallback']);
        Setting::create(['key' => 'social.page_token', 'value' => 'invalid-ciphertext']);
        Setting::create(['key' => 'social.connection_ok', 'value' => '1']);
        $settings = new SocialSettings;

        $this->assertNull($settings->token());
        $this->assertFalse($settings->status()['connected']);
    }

    public function test_valid_settings_sort_five_distinct_times_and_only_store_allowed_fields(): void
    {
        $settings = new SocialSettings;
        $settings->save([
            'enabled' => true, 'theme' => 'new_year',
            'slots' => ['21:00', '13:00', '10:00', '19:00', '16:00'],
            'page_id' => '999', 'minimum_price' => 1, 'page_access_token' => 'untrusted-extra-field',
        ]);

        $this->assertTrue($settings->values()['enabled']);
        $this->assertSame('new_year', $settings->values()['theme']);
        $this->assertSame(PostPlanner::DEFAULT_SLOTS, $settings->values()['slots']);
        $this->assertSame(SocialSettings::PAGE_ID, $settings->values()['page_id']);
        $this->assertEqualsCanonicalizing(['social.enabled', 'social.theme', 'social.slots'], Setting::query()->pluck('key')->all());
        $this->assertNull($settings->token());
    }

    #[DataProvider('invalidSettings')]
    public function test_settings_validation_rejects_invalid_themes_times_or_artwork_without_writing(array $override): void
    {
        $settings = new SocialSettings;
        try {
            $settings->save(array_replace([
                'enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS,
            ], $override));
            $this->fail('Invalid social settings must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, Setting::count());
    }

    public static function invalidSettings(): iterable
    {
        yield 'unknown theme' => [['theme' => 'spring_sale']];
        yield 'non-boolean enabled' => [['enabled' => 'yes']];
        yield 'four slots' => [['slots' => ['10:00', '13:00', '16:00', '19:00']]];
        yield 'six slots' => [['slots' => ['08:00', '10:00', '13:00', '16:00', '19:00', '21:00']]];
        yield 'duplicate slot' => [['slots' => ['10:00', '13:00', '13:00', '19:00', '21:00']]];
        yield 'invalid hour' => [['slots' => ['24:00', '13:00', '16:00', '19:00', '21:00']]];
        yield 'invalid minute' => [['slots' => ['10:60', '13:00', '16:00', '19:00', '21:00']]];
        yield 'non-padded time' => [['slots' => ['9:00', '13:00', '16:00', '19:00', '21:00']]];
        yield 'trailing newline' => [['slots' => ["10:00\n", '13:00', '16:00', '19:00', '21:00']]];
        yield 'nested time' => [['slots' => [['10:00'], '13:00', '16:00', '19:00', '21:00']]];
        yield 'named color' => [['artwork' => ['accent_color' => 'orange']]];
        yield 'short hex color' => [['artwork' => ['accent_color' => '#fff']]];
        yield 'invalid hex color' => [['artwork' => ['accent_color' => '#12345g']]];
        yield 'hex color with newline' => [['artwork' => ['accent_color' => "#123456\n"]]];
        yield 'headline exceeds 70 characters' => [['artwork' => ['headline' => str_repeat('ა', 71)]]];
        yield 'CTA exceeds 28 characters' => [['artwork' => ['cta' => str_repeat('ბ', 29)]]];
        yield 'tagline exceeds 45 characters' => [['artwork' => ['tagline' => str_repeat('გ', 46)]]];
        yield 'HTML headline' => [['artwork' => ['headline' => '<b>შეთავაზება</b>']]];
        yield 'multiline CTA' => [['artwork' => ['cta' => "ნახე\nპროდუქტი"]]];
        yield 'control character in tagline' => [['artwork' => ['tagline' => "ჩვენი\x7fმაღაზია"]]];
        yield 'nested artwork credential' => [['artwork' => ['access_token' => 'synthetic-untrusted-token']]];
        yield 'nested artwork array' => [['artwork' => ['headline' => ['untrusted']]]];
    }

    public function test_custom_artwork_values_persist_and_an_older_save_preserves_them(): void
    {
        $settings = new SocialSettings;
        $custom = [
            'accent_color' => '#2468Ab',
            'headline' => 'შენი ახალი ტექნიკა',
            'cta' => 'შეარჩიე ახლავე',
            'tagline' => 'შეთავაზებები შენთვის',
        ];
        $settings->save(['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS, 'artwork' => $custom]);
        $this->assertSame($custom, (new SocialSettings)->values()['artwork']);

        $settings->save(['enabled' => true, 'theme' => 'new_year', 'slots' => PostPlanner::DEFAULT_SLOTS]);
        $this->assertSame($custom, (new SocialSettings)->values()['artwork']);
        $this->assertTrue($settings->values()['enabled']);
        $this->assertSame('new_year', $settings->values()['theme']);
    }

    public function test_partial_artwork_update_preserves_other_fields_and_blank_or_null_resets_only_that_field(): void
    {
        $settings = new SocialSettings;
        $custom = ['accent_color' => '#2468AB', 'headline' => 'დღის ტექნიკა', 'cta' => 'ნახე ახლავე', 'tagline' => 'შენი მაღაზია'];
        $base = ['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS];
        $settings->save($base + ['artwork' => $custom]);
        $settings->save($base + ['artwork' => ['headline' => 'ახალი სათაური']]);
        $this->assertSame(array_replace($custom, ['headline' => 'ახალი სათაური']), $settings->values()['artwork']);

        $settings->save($base + ['artwork' => ['headline' => '', 'cta' => null]]);
        $this->assertSame(array_replace($custom, ['headline' => '', 'cta' => '']), $settings->values()['artwork']);
        $settings->save($base + ['artwork' => ['accent_color' => '', 'tagline' => '']]);
        $this->assertSame(['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => ''], $settings->values()['artwork']);
    }

    public function test_artwork_text_limits_count_unicode_characters_and_accept_plain_text_at_the_limit(): void
    {
        $artwork = [
            'accent_color' => '#123aBC',
            'headline' => str_repeat('ა', 70),
            'cta' => str_repeat('ბ', 28),
            'tagline' => str_repeat('გ', 45),
        ];
        $settings = new SocialSettings;
        $settings->save(['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS, 'artwork' => $artwork]);

        $this->assertSame($artwork, $settings->values()['artwork']);
    }

    public function test_unknown_connection_error_is_replaced_by_a_safe_reason(): void
    {
        $settings = new SocialSettings;
        $settings->recordStatus(false, 'access_token=synthetic-secret');

        $this->assertSame('connection_failed', $settings->status()['reason']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($settings->status()));
        $this->assertStringNotContainsString('synthetic-secret', json_encode(Setting::stored()));
    }

    public function test_invalid_stored_theme_and_times_fall_back_to_safe_defaults(): void
    {
        Setting::create(['key' => 'social.theme', 'value' => 'unexpected']);
        Setting::create(['key' => 'social.slots', 'value' => '["10:00","10:00","25:00"]']);
        $values = (new SocialSettings)->values();

        $this->assertSame('auto', $values['theme']);
        $this->assertSame(PostPlanner::DEFAULT_SLOTS, $values['slots']);
    }

    public function test_channel_settings_preserve_legacy_facebook_and_record_only_new_activation_times(): void
    {
        $settings = new SocialSettings;
        $this->assertSame(SocialSettings::DEFAULT_CHANNELS, $settings->values()['channels']);
        $base = ['enabled' => false, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS];
        $settings->save($base + ['channels' => ['instagram_feed' => true, 'facebook_story' => true]]);
        $activated = $settings->values()['channel_enabled_at'];
        $this->assertTrue($settings->values()['channels']['facebook_feed']);
        $this->assertTrue($settings->values()['channels']['instagram_feed']);
        $this->assertFalse($settings->values()['channels']['instagram_story']);
        $this->assertNotNull($activated['instagram_feed']);
        $this->assertNull($activated['facebook_feed']);

        $settings->save($base);
        $this->assertSame($activated, $settings->values()['channel_enabled_at']);
        $this->assertTrue($settings->values()['channels']['instagram_feed']);
        $settings->save($base + ['channels' => ['instagram_feed' => true]]);
        $this->assertSame($activated['instagram_feed'], $settings->values()['channel_enabled_at']['instagram_feed']);
    }

    public function test_channel_and_instagram_errors_are_allowlisted_and_new_channels_are_unverified_by_default(): void
    {
        $settings = new SocialSettings;
        $settings->storeConnection('synthetic-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $status = $settings->status();
        $this->assertTrue($status['channel_status']['facebook_feed']['connected']);
        foreach (['instagram_feed', 'facebook_story', 'instagram_story'] as $channel) {
            $this->assertFalse($status['channel_status'][$channel]['connected']);
        }
        $settings->recordInstagramStatus(false, 'access_token=synthetic-private-secret');
        $settings->recordChannelStatus('instagram_feed', false, 'access_token=synthetic-private-secret');
        $this->assertSame('connection_failed', $settings->status()['instagram']['reason']);
        $this->assertSame('connection_failed', $settings->status()['channel_status']['instagram_feed']['reason']);
        $this->assertStringNotContainsString('synthetic-private-secret', json_encode([$settings->status(), Setting::stored()]));

        $settings->recordInstagramStatus(true, 'connected', SocialSettings::INSTAGRAM_ID, 'iapi_ge', 'BUSINESS');
        $settings->recordChannelStatus('instagram_feed', true, 'connected');
        $this->assertTrue($settings->status()['instagram']['connected']);
        $this->assertTrue($settings->status()['channel_status']['instagram_feed']['connected']);
        $settings->recordStatus(false, 'token_expired');
        $this->assertFalse($settings->status()['instagram']['connected']);
        $this->assertFalse($settings->status()['channel_status']['instagram_feed']['connected']);
    }

    public function test_rotating_the_page_credential_invalidates_instagram_and_new_channel_readiness_only(): void
    {
        $settings = new SocialSettings;
        $settings->storeConnection('synthetic-first-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $settings->recordInstagramStatus(true, 'connected', SocialSettings::INSTAGRAM_ID, 'iapi_ge', 'BUSINESS');
        foreach (SocialSettings::CHANNELS as $channel) {
            $settings->recordChannelStatus($channel, true, 'connected');
        }
        $settings->save(['enabled' => true, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'channels' => array_fill_keys(SocialSettings::CHANNELS, true)]);
        $settings->storeConnection('synthetic-first-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $this->assertTrue($settings->status()['instagram']['connected']);
        $this->assertTrue($settings->status()['channel_status']['instagram_story']['connected']);

        $settings->storeConnection('synthetic-replacement-page-token', SocialSettings::PAGE_ID, 'IAPI');

        $this->assertFalse($settings->status()['instagram']['connected']);
        $this->assertTrue($settings->status()['channel_status']['facebook_feed']['connected']);
        foreach (['instagram_feed', 'facebook_story', 'instagram_story'] as $channel) {
            $this->assertFalse($settings->status()['channel_status'][$channel]['connected']);
            $this->assertSame('not_checked', $settings->status()['channel_status'][$channel]['reason']);
        }
        $this->assertSame(array_fill_keys(SocialSettings::CHANNELS, true), $settings->values()['channels']);
        $this->assertStringNotContainsString('synthetic-replacement-page-token', json_encode($settings->status()));
    }

    public function test_connection_to_another_page_is_rejected_without_storing_a_token(): void
    {
        $settings = new SocialSettings;
        try {
            $settings->storeConnection('synthetic-wrong-page-token', '999', 'Another Page');
            $this->fail('A different Page must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('wrong_page', $exception->getMessage());
        }
        $this->assertNull($settings->token());
        $this->assertSame(0, Setting::count());
    }
}
