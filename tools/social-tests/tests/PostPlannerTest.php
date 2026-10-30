<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPost;
use App\Services\Social\PostPlanner;
use App\Services\Social\ProductSelector;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

class PostPlannerTest extends TestCase
{
    public function test_five_future_tbilisi_slots_are_planned_once_with_unique_products(): void
    {
        foreach (range(1, 7) as $category) {
            $this->product(['category_id' => $category]);
        }
        $planner = new PostPlanner(new ProductSelector);
        $first = $planner->plan('103447072710661');
        $second = $planner->plan('103447072710661');

        $this->assertSame(5, $first['created']);
        $this->assertSame(0, $first['shortfall']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(5, SocialPost::count());
        $this->assertSame(['06:00', '09:00', '12:00', '15:00', '17:00'], $first['posts']->map(fn ($post) => $post->scheduled_for->utc()->format('H:i'))->all());
        $this->assertSame(5, $first['posts']->pluck('product_id')->unique()->count());
        $this->assertSame(['evergreen'], $first['posts']->pluck('theme')->unique()->values()->all());
        $this->assertSame(['draft'], $first['posts']->pluck('status')->unique()->values()->all());
    }

    public function test_past_and_current_slots_are_not_backfilled(): void
    {
        foreach (range(1, 5) as $category) {
            $this->product(['category_id' => $category]);
        }
        $at = CarbonImmutable::parse('2026-10-29 16:00:00', 'Asia/Tbilisi');
        $result = (new PostPlanner(new ProductSelector))->plan('103447072710661', $at);

        $this->assertSame(2, $result['available_slots']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(['15:00', '17:00'], $result['posts']->map(fn ($post) => $post->scheduled_for->utc()->format('H:i'))->all());
    }

    public function test_changing_times_after_five_plans_does_not_add_or_reschedule_posts(): void
    {
        foreach (range(1, 10) as $category) {
            $this->product(['category_id' => $category]);
        }
        $planner = new PostPlanner(new ProductSelector);
        $original = $planner->plan('103447072710661');
        $originalRows = $original['posts']->map->getAttributes()->all();
        $changed = $planner->plan('103447072710661', null, 'new_year', ['09:00', '12:00', '15:00', '18:00', '20:00']);

        $this->assertSame(0, $changed['created']);
        $this->assertSame(0, $changed['shortfall']);
        $this->assertSame(5, SocialPost::count());
        $this->assertSame($originalRows, $changed['posts']->map->getAttributes()->all());
    }

    public function test_changed_times_only_fill_the_three_remaining_daily_places(): void
    {
        $this->product(['category_id' => 1]);
        $this->product(['category_id' => 2]);
        $planner = new PostPlanner(new ProductSelector);
        $original = $planner->plan('103447072710661');
        $originalRows = $original['posts']->map->getAttributes()->all();
        $this->assertSame(2, $original['created']);

        foreach (range(3, 10) as $category) {
            $this->product(['category_id' => $category]);
        }
        $changed = $planner->plan('103447072710661', null, 'new_year', ['09:00', '12:00', '15:00', '18:00', '20:00']);

        $this->assertSame(3, $changed['created']);
        $this->assertSame(0, $changed['shortfall']);
        $this->assertSame(5, SocialPost::count());
        $this->assertSame($originalRows, SocialPost::whereKey($original['posts']->modelKeys())->orderBy('scheduled_for')->get()->map->getAttributes()->all());
        $new = $changed['posts']->whereNotIn('id', $original['posts']->modelKeys())->values();
        $this->assertSame(['05:00', '08:00', '11:00'], $new->map(fn ($post) => $post->scheduled_for->utc()->format('H:i'))->all());
        $this->assertSame(['new_year'], $new->pluck('theme')->unique()->values()->all());
    }

    public function test_changed_times_report_only_the_remaining_daily_capacity_as_shortfall(): void
    {
        $this->product();
        $this->product();
        $planner = new PostPlanner(new ProductSelector);
        $planner->plan('103447072710661');
        $changed = $planner->plan('103447072710661', null, 'auto', ['09:00', '12:00', '15:00', '18:00', '20:00']);

        $this->assertSame(0, $changed['created']);
        $this->assertSame(3, $changed['shortfall']);
        $this->assertSame(2, SocialPost::count());
    }

    public function test_shortfall_is_reported_honestly_without_repeating_products(): void
    {
        $this->product();
        $this->product();
        $result = (new PostPlanner(new ProductSelector))->plan('103447072710661');

        $this->assertSame(2, $result['created']);
        $this->assertSame(3, $result['shortfall']);
        $this->assertCount(2, $result['posts']);
    }

    public function test_no_posts_are_planned_after_the_final_slot(): void
    {
        $this->product();
        $result = (new PostPlanner(new ProductSelector))->plan('103447072710661', CarbonImmutable::parse('2026-10-29 22:00:00', 'Asia/Tbilisi'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['shortfall']);
        $this->assertSame(0, $result['available_slots']);
    }

    public function test_theme_follows_tbilisi_season_boundaries_and_explicit_override(): void
    {
        $planner = new PostPlanner(new ProductSelector);
        foreach ([
            ['2026-10-31 19:59:59 UTC', 'evergreen'],
            ['2026-10-31 20:00:00 UTC', 'black_friday'],
            ['2026-11-30 20:00:00 UTC', 'new_year'],
            ['2027-01-14 19:59:59 UTC', 'new_year'],
            ['2027-01-14 20:00:00 UTC', 'evergreen'],
        ] as [$date, $expected]) {
            $this->assertSame($expected, $planner->themeFor(CarbonImmutable::parse($date)));
        }
        $this->assertSame('new_year', $planner->themeFor(CarbonImmutable::now(), 'new_year'));
    }

    public function test_database_rejects_duplicate_slot(): void
    {
        $first = $this->product();
        $second = $this->product();
        $this->post($first);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->post($second);
    }

    public function test_database_rejects_same_product_twice_on_the_same_day(): void
    {
        $product = $this->product();
        $this->post($product);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->post($product, ['scheduled_for' => CarbonImmutable::parse('2026-10-28 09:00:00', 'UTC')]);
    }

    public function test_exactly_five_distinct_valid_slots_are_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PostPlanner(new ProductSelector))->plan('103447072710661', null, 'auto', ['10:00', '13:00', '13:00', '19:00', '21:00']);
    }
}
