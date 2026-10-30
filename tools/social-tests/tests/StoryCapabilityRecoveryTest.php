<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\Setting;
use App\Services\Social\MetaChannelPublisher;
use App\Services\Social\PublicationPreparer;
use App\Services\Social\SocialSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;

class StoryCapabilityRecoveryTest extends TestCase
{
    private SocialSettings $settings;

    private int $quota = 0;

    private bool $networkFailure = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new SocialSettings;
        $this->settings->storeConnection('synthetic-capability-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $this->settings->recordInstagramStatus(true, 'connected', SocialSettings::INSTAGRAM_ID, 'iapi_ge');
        $this->settings->recordChannelStatus('instagram_story', true, 'connected');
        Http::fake(function (Request $request) {
            if ($this->networkFailure) {
                throw new ConnectionException('Raw synthetic-private-secret must never be saved');
            }

            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/v26.0/me' => ['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI'],
                '/v26.0/'.SocialSettings::PAGE_ID => ['instagram_business_account' => ['id' => SocialSettings::INSTAGRAM_ID]],
                '/v26.0/'.SocialSettings::INSTAGRAM_ID => ['id' => SocialSettings::INSTAGRAM_ID, 'username' => 'iapi_ge'],
                '/v26.0/'.SocialSettings::INSTAGRAM_ID.'/content_publishing_limit' => ['data' => [['quota_usage' => $this->quota, 'config' => ['quota_total' => 100]]]],
                default => [],
            });
        });
    }

    public function test_quota_exhaustion_does_not_erase_proof_and_story_recovers_automatically(): void
    {
        $publisher = $this->publisher();
        $this->quota = 100;
        $bad = $publisher->connectionStatus();
        $this->assertFalse($bad['channel_status']['instagram_story']['connected']);
        $this->assertSame('quota_exceeded', $bad['channel_status']['instagram_story']['reason']);
        $this->assertTrue($bad['instagram']['story_verified']);
        $this->quota = 0;
        $good = $publisher->connectionStatus();

        $this->assertTrue($good['instagram']['story_verified']);
        $this->assertTrue($good['channel_status']['instagram_story']['connected']);
        $this->assertSame('connected', $good['channel_status']['instagram_story']['reason']);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_network_failure_recovers_without_a_new_capability_probe_and_never_saves_raw_error(): void
    {
        $publisher = $this->publisher();
        $this->networkFailure = true;
        $publisher->connectionStatus();
        $this->assertTrue($this->settings->status()['instagram']['story_verified']);
        $this->assertStringNotContainsString('synthetic-private-secret', json_encode(Setting::stored()));
        $this->networkFailure = false;

        $this->assertTrue($publisher->connectionStatus()['channel_status']['instagram_story']['connected']);
    }

    public function test_proof_is_private_and_bound_to_both_the_pinned_account_and_credential_version(): void
    {
        $proof = Setting::stored()['social.instagram.story_proof'];
        $this->assertStringNotContainsString('synthetic-capability-page-token', $proof);
        $this->assertStringNotContainsString($proof, json_encode($this->settings->status()));
        $this->settings->storeConnection('synthetic-capability-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $this->assertTrue($this->settings->status()['instagram']['story_verified']);
        $this->settings->storeConnection('synthetic-new-capability-page-token', SocialSettings::PAGE_ID, 'IAPI');
        $this->assertFalse($this->settings->status()['instagram']['story_verified']);
        $status = $this->publisher()->connectionStatus();
        $this->assertFalse($status['channel_status']['instagram_story']['connected']);
        $this->assertSame('not_checked', $status['channel_status']['instagram_story']['reason']);
    }

    public function test_identity_change_explicitly_invalidates_the_old_story_proof(): void
    {
        $this->settings->recordInstagramStatus(false, 'instagram_changed');
        $this->assertFalse($this->settings->status()['instagram']['story_verified']);
        $this->assertFalse($this->publisher()->connectionStatus()['channel_status']['instagram_story']['connected']);
    }

    private function publisher(): MetaChannelPublisher
    {
        return new MetaChannelPublisher($this->settings, Mockery::mock(PublicationPreparer::class));
    }
}
