<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPost;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\ProductSelector;
use App\Services\Social\SocialSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;

class SocialTimezoneTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
        parent::setUp();
        date_default_timezone_set('Asia/Tbilisi');
        config(['app.timezone' => 'Asia/Tbilisi']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function test_ledger_dates_round_trip_as_utc_under_the_production_tbilisi_timezone(): void
    {
        $post = $this->post($this->product(), [
            'status' => SocialPost::STATUS_READY,
            'scheduled_for' => CarbonImmutable::parse('2026-10-29 10:00:00', 'Asia/Tbilisi'),
            'scheduled_date' => '2026-10-29',
            'claimed_at' => CarbonImmutable::parse('2026-10-29 09:55:00', 'Asia/Tbilisi'),
            'published_at' => null,
        ])->fresh();

        $this->assertSame('2026-10-29 06:00:00', $post->getRawOriginal('scheduled_for'));
        $this->assertSame('2026-10-29 05:55:00', $post->getRawOriginal('claimed_at'));
        $this->assertSame('2026-10-29T06:00:00+00:00', $post->scheduled_for->toIso8601String());
        $this->assertSame('2026-10-29T05:55:00+00:00', $post->claimed_at->toIso8601String());
        $this->assertTrue($post->scheduled_for->isFuture());
        $this->assertSame('2026-10-29 04:00:00', $post->getRawOriginal('created_at'));
        $this->assertSame('2026-10-29T04:00:00+00:00', $post->created_at->toIso8601String());
        $this->assertNull($post->published_at);

        $this->setClock('2026-10-29 10:00:00');
        $post->forceFill(['published_at' => now(), 'status' => SocialPost::STATUS_PUBLISHED])->save();
        $post->refresh();
        $this->assertSame('2026-10-29 06:00:00', $post->getRawOriginal('published_at'));
        $this->assertSame('2026-10-29 06:00:00', $post->getRawOriginal('updated_at'));
        $this->assertSame('UTC', $post->published_at->getTimezone()->getName());
    }

    public function test_planning_is_stable_and_does_not_mark_the_ten_am_slot_past_at_eight_am(): void
    {
        foreach (range(1, 5) as $category) {
            $this->product(['category_id' => $category]);
        }
        $planner = new PostPlanner(new ProductSelector);
        $first = $planner->plan(SocialSettings::PAGE_ID);
        $second = $planner->plan(SocialSettings::PAGE_ID);

        $this->assertSame(5, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(5, SocialPost::count());
        $this->assertSame(['06:00', '09:00', '12:00', '15:00', '17:00'], $first['posts']->map(fn ($post) => $post->scheduled_for->utc()->format('H:i'))->all());
        $this->assertSame(['10:00', '13:00', '16:00', '19:00', '21:00'], $first['posts']->map(fn ($post) => $post->scheduled_for->setTimezone('Asia/Tbilisi')->format('H:i'))->all());
        $this->assertTrue($first['posts']->first()->scheduled_for->isFuture());
        $this->assertSame($first['posts']->modelKeys(), $second['posts']->modelKeys());
    }

    public function test_cached_schedule_reads_and_later_content_updates_preserve_the_original_slot(): void
    {
        $post = $this->post($this->product(), [
            'status' => SocialPost::STATUS_DRAFT,
            'scheduled_for' => CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            'scheduled_date' => '2026-10-29',
        ])->fresh();
        $this->assertSame('16:00', $post->scheduled_for->setTimezone('Asia/Tbilisi')->format('H:i'));
        $this->assertSame('12:00', $post->scheduled_for->utc()->format('H:i'));
        $this->setClock('2026-10-29 18:40:36');
        $post->forceFill(['status' => SocialPost::STATUS_READY, 'caption' => 'Synthetic prepared caption'])->save();

        $this->assertArrayNotHasKey('scheduled_for', $post->getChanges());
        $post->refresh();
        $this->assertSame('2026-10-29 12:00:00', $post->getRawOriginal('scheduled_for'));
        $this->assertSame('2026-10-29 14:40:36', $post->getRawOriginal('updated_at'));
        $this->assertSame('2026-10-29', $post->scheduled_date);
    }

    public function test_a_local_day_can_start_on_the_previous_utc_date_without_shifting_the_slot(): void
    {
        foreach (range(1, 5) as $category) {
            $this->product(['category_id' => $category]);
        }
        $this->setClock('2026-10-30 00:01:00');
        $plan = (new PostPlanner(new ProductSelector))->plan(SocialSettings::PAGE_ID, slots: ['00:30', '10:00', '13:00', '19:00', '21:00']);
        $first = $plan['posts']->first();

        $this->assertSame('2026-10-30', $first->scheduled_date);
        $this->assertSame('2026-10-29 20:30:00', $first->getRawOriginal('scheduled_for'));
        $this->assertSame('2026-10-30 00:30:00', $first->scheduled_for->setTimezone('Asia/Tbilisi')->format('Y-m-d H:i:s'));
        $this->assertTrue($first->scheduled_for->isFuture());
    }

    public function test_publisher_waits_until_ten_am_tbilisi_and_does_not_publish_the_later_rows(): void
    {
        foreach (range(1, 5) as $category) {
            $this->product(['category_id' => $category]);
        }
        $settings = new SocialSettings;
        $settings->save(['enabled' => true, 'theme' => 'auto', 'slots' => PostPlanner::DEFAULT_SLOTS]);
        config(['social.page_access_token' => 'synthetic-timezone-page-token']);
        $plan = (new PostPlanner(new ProductSelector))->plan(SocialSettings::PAGE_ID);
        $preparer = Mockery::mock(PostPreparer::class);
        $preparer->shouldReceive('prepare')->once()->andReturnUsing(function (SocialPost $post) {
            $this->assertSame('2026-10-29T06:00:00+00:00', $post->scheduled_for->toIso8601String());
            $post->forceFill(['caption' => 'Synthetic caption', 'image_path' => 'social-posts/fixture.png'])->save();

            return $post;
        });
        $preparer->shouldReceive('imagePath')->once()->andReturn($this->fixtureRoot.'/public/products/fixture.png');
        Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
            '/v26.0/me' => Http::response(['id' => SocialSettings::PAGE_ID, 'name' => 'IAPI']),
            '/v26.0/'.SocialSettings::PAGE_ID.'/photos' => Http::response(['post_id' => SocialSettings::PAGE_ID.'_123']),
            '/v26.0/'.SocialSettings::PAGE_ID.'_123' => Http::response(['id' => SocialSettings::PAGE_ID.'_123', 'from' => ['id' => SocialSettings::PAGE_ID], 'is_published' => true]),
            default => Http::response([], 500),
        });
        $publisher = new PagePublisher($settings, $preparer);

        $this->assertSame(0, $publisher->publishDue());
        $this->setClock('2026-10-29 09:59:59');
        $this->assertSame(0, $publisher->publishDue());
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->setClock('2026-10-29 10:00:00');
        $this->assertSame(1, $publisher->publishDue());
        $this->assertSame(SocialPost::STATUS_PUBLISHED, $plan['posts']->first()->fresh()->status);
        $this->assertSame(1, SocialPost::where('status', SocialPost::STATUS_PUBLISHED)->count());
        $this->assertSame(4, SocialPost::where('status', SocialPost::STATUS_DRAFT)->count());
        $this->assertSame('2026-10-29 06:00:00', $plan['posts']->first()->fresh()->getRawOriginal('published_at'));
    }

    private function setClock(string $localTime): void
    {
        $now = CarbonImmutable::parse($localTime, 'Asia/Tbilisi');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
    }
}
