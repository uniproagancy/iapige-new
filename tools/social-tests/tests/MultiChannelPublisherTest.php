<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Services\Social\MetaChannelPublisher;
use App\Services\Social\MultiChannelPublisher;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\ProductSelector;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Mockery;

class MultiChannelPublisherTest extends TestCase
{
    private bool $connected = true;

    private int $manualFacebookWrites = 0;

    private int $otherWrites = 0;

    protected function setUp(): void
    {
        parent::setUp();
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        config(['social.page_access_token' => 'synthetic-orchestration-token']);
    }

    public function test_manual_extra_does_not_use_the_five_automatic_places_and_a_double_click_does_not_repeat(): void
    {
        foreach (range(1, 5) as $index) {
            $this->post($this->product(), ['scheduled_date' => '2026-10-29', 'scheduled_for' => now('UTC')->addHours($index)]);
        }
        $product = $this->product(['price' => 99, 'old_price' => null]);
        $service = $this->service();
        $key = str_repeat('a', 64);
        $first = $service->publishProduct($product->id, 1, $key);
        $second = $service->publishProduct($product->id, 1, $key);

        $this->assertSame(4, $first['published']);
        $this->assertSame(4, $second['published']);
        $this->assertSame(5, SocialPost::count());
        $this->assertSame(4, SocialPublication::where('origin', 'manual')->count());
        $this->assertSame(1, $this->manualFacebookWrites);
        $this->assertSame(3, $this->otherWrites);
    }

    public function test_one_unknown_channel_does_not_prevent_the_other_three_and_is_never_reposted(): void
    {
        $service = $this->service(unknownInstagram: true);
        $product = $this->product();
        $result = $service->publishProduct($product->id, 1, str_repeat('a', 64));
        $reload = $service->publishProduct($product->id, 1, str_repeat('b', 64));

        $this->assertSame(3, $result['published']);
        $this->assertSame(1, $result['unknown']);
        $this->assertSame($result['request_key'], $reload['request_key']);
        $this->assertSame(4, SocialPublication::count());
        $this->assertSame(1, $this->manualFacebookWrites);
        $this->assertSame(3, $this->otherWrites);
    }

    public function test_disconnected_channels_block_before_writes_and_resume_the_same_request(): void
    {
        $this->connected = false;
        $service = $this->service();
        $product = $this->product();
        $first = $service->publishProduct($product->id, 1, str_repeat('a', 64));
        $this->assertSame(4, $first['blocked']);
        $this->assertSame(0, $this->manualFacebookWrites + $this->otherWrites);
        $this->connected = true;
        $second = $service->publishProduct($product->id, 1, str_repeat('b', 64));

        $this->assertSame($first['request_key'], $second['request_key']);
        $this->assertSame(4, $second['published']);
        $this->assertSame(4, SocialPublication::count());
    }

    public function test_an_already_published_legacy_post_is_only_mirrored_and_not_backfilled_to_instagram(): void
    {
        $this->clock('2026-10-29 16:15:00');
        $old = $this->post($this->product(), [
            'scheduled_date' => '2026-10-29', 'scheduled_for' => CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            'status' => 'published', 'post_id' => SocialSettings::PAGE_ID.'_old',
        ]);
        foreach (range(1, 5) as $index) {
            $this->product(['category_id' => $index + 1]);
        }
        $settings = new SocialSettings;
        $settings->save(['enabled' => true, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'channels' => array_fill_keys(SocialPublication::CHANNELS, true)]);
        $this->service()->run();
        $oldChannels = SocialPublication::where('social_post_id', $old->id)->pluck('channel')->all();

        $this->assertSame(['facebook_feed'], $oldChannels);
        $this->assertSame(2, SocialPublication::where('channel', 'instagram_feed')->count());
        $this->assertSame(3, SocialPost::where('scheduled_date', '2026-10-29')->count());
        $this->assertSame(0, $this->manualFacebookWrites);
    }

    public function test_automatic_future_channels_share_exactly_five_products_and_repeated_runs_add_nothing(): void
    {
        foreach (range(1, 7) as $index) {
            $this->product(['category_id' => $index]);
        }
        (new SocialSettings)->save(['enabled' => true, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS,
            'channels' => array_fill_keys(SocialPublication::CHANNELS, true)]);
        $service = $this->service();
        $service->run();
        $service->run();

        $this->assertSame(5, SocialPost::count());
        $this->assertSame(20, SocialPublication::where('origin', 'automatic')->count());
        $this->assertSame(5, SocialPublication::where('channel', 'instagram_feed')->distinct('product_id')->count('product_id'));
        $this->assertSame(0, $this->otherWrites);
    }

    public function test_mirror_restores_only_verified_fixed_page_feed_links_and_never_invents_story_links(): void
    {
        $cases = [
            ['published', SocialSettings::PAGE_ID.'_123456', 'https://www.facebook.com/'.SocialSettings::PAGE_ID.'_123456'],
            ['published', '999_123456', null],
            ['published', '123456', null],
            ['published', SocialSettings::PAGE_ID.'_123456evil', null],
            ['published', SocialSettings::PAGE_ID."_123456\n", null],
            ['unknown', SocialSettings::PAGE_ID.'_123456', null],
            ['publishing', SocialSettings::PAGE_ID.'_123456', null],
            ['ready', SocialSettings::PAGE_ID.'_123456', null],
        ];
        foreach ($cases as $index => [$status, $id]) {
            $posts[] = $this->post($this->product(), [
                'status' => $status, 'post_id' => $id, 'scheduled_for' => now('UTC')->addMinutes($index),
            ]);
        }
        $first = $posts[0];
        $mirrored = SocialPublication::create([
            'origin' => 'automatic', 'social_post_id' => $first->id, 'product_id' => $first->product_id,
            'request_key' => hash('sha256', 'automatic|'.$first->id), 'channel' => 'facebook_feed',
            'account_id' => SocialSettings::PAGE_ID, 'scheduled_for' => $first->scheduled_for,
            'status' => 'published', 'external_id' => $first->post_id, 'permalink' => null,
        ]);
        $story = SocialPublication::create([
            'origin' => 'automatic', 'social_post_id' => $first->id, 'product_id' => $first->product_id,
            'request_key' => hash('sha256', 'automatic|'.$first->id), 'channel' => 'facebook_story',
            'account_id' => SocialSettings::PAGE_ID, 'scheduled_for' => $first->scheduled_for,
            'status' => 'published', 'external_id' => $first->post_id, 'permalink' => null,
        ]);
        $method = new \ReflectionMethod(MultiChannelPublisher::class, 'mirrorFacebook');
        $method->invoke($this->service(), SocialPost::query()->orderBy('id')->get(), (new SocialSettings)->values());

        $this->assertSame($cases[0][2], $mirrored->fresh()->permalink);
        foreach ($posts as $index => $post) {
            $this->assertSame($cases[$index][2], SocialPublication::where('social_post_id', $post->id)
                ->where('channel', 'facebook_feed')->value('permalink'));
        }
        $this->assertNull($story->fresh()->permalink);
        $this->assertSame(0, $this->manualFacebookWrites + $this->otherWrites);
    }

    public function test_manual_server_resume_does_not_depend_on_automatic_enabled_switch(): void
    {
        $this->connected = false;
        $service = $this->service();
        $product = $this->product();
        $service->publishProduct($product->id, 1, str_repeat('a', 64));
        $this->connected = true;
        $service->run();

        $this->assertFalse((new SocialSettings)->values()['enabled']);
        $this->assertSame(0, SocialPost::count());
        $this->assertSame(4, SocialPublication::where('status', 'published')->count());
    }

    public function test_manual_requests_expire_instead_of_surprising_the_owner_days_later(): void
    {
        $this->connected = false;
        $service = $this->service();
        $service->publishProduct($this->product()->id, 1, str_repeat('a', 64));
        $this->clock('2026-10-29 11:01:00');
        $this->connected = true;
        $service->run();

        $this->assertSame(4, SocialPublication::where('status', 'skipped')->count());
        $this->assertSame(0, $this->manualFacebookWrites + $this->otherWrites);
    }

    private function service(bool $unknownInstagram = false): MultiChannelPublisher
    {
        $settings = new SocialSettings;
        $selector = new ProductSelector;
        $facebook = Mockery::mock(PagePublisher::class);
        $facebook->shouldReceive('connectionStatus')->andReturnUsing(function () use ($settings) {
            $settings->recordStatus($this->connected, $this->connected ? 'connected' : 'connection_missing');

            return $settings->status();
        });
        $facebook->shouldReceive('reconcileUnknown')->andReturn(0);
        $facebook->shouldReceive('reconcileManualUnknown')->andReturn(0);
        $facebook->shouldReceive('publishDue')->andReturn(0);
        $facebook->shouldReceive('publishManual')->andReturnUsing(function (SocialPublication $publication) {
            $this->manualFacebookWrites++;
            $publication->forceFill(['status' => 'published', 'external_id' => SocialSettings::PAGE_ID.'_'.$publication->id,
                'published_at' => now('UTC')])->save();

            return true;
        });
        $channels = Mockery::mock(MetaChannelPublisher::class);
        $channels->shouldReceive('connectionStatus')->andReturnUsing(function () use ($settings) {
            foreach (SocialPublication::CHANNELS as $channel) {
                $settings->recordChannelStatus($channel, $this->connected, $this->connected ? 'connected' : 'connection_missing');
            }

            return $settings->status();
        });
        $channels->shouldReceive('reconcileUnknown')->andReturn(0);
        $channels->shouldReceive('publish')->andReturnUsing(function (SocialPublication $publication) use ($unknownInstagram) {
            if (! $this->connected) {
                $publication->forceFill(['status' => 'blocked', 'error' => 'connection_missing'])->save();

                return false;
            }
            $this->otherWrites++;
            $unknown = $unknownInstagram && $publication->channel === 'instagram_feed';
            $publication->forceFill(['status' => $unknown ? 'unknown' : 'published',
                'external_id' => (string) (1000 + $publication->id), 'published_at' => $unknown ? null : now('UTC')])->save();

            return ! $unknown;
        });
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->andReturnUsing(function (SocialPost $post) {
            $post->forceFill(['status' => 'ready'])->save();

            return $post;
        });

        return new MultiChannelPublisher($settings, $selector, new PostPlanner($selector), $preparer, $facebook, $channels);
    }

    private function clock(string $localTime): void
    {
        $now = CarbonImmutable::parse($localTime, 'Asia/Tbilisi');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
    }
}
