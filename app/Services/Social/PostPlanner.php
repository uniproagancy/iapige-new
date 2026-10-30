<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostPlanner
{
    public const DEFAULT_SLOTS = ['10:00', '13:00', '16:00', '19:00', '21:00'];

    public const DAILY_LIMIT = 5;

    public const THEMES = ['auto', 'black_friday', 'new_year', 'evergreen'];

    public function __construct(protected ProductSelector $selector) {}

    /**
     * @return array{posts: Collection, created: int, available_slots: int, shortfall: int}
     */
    public function plan(
        string $pageId,
        ?CarbonInterface $at = null,
        string $theme = 'auto',
        array $slots = self::DEFAULT_SLOTS,
    ): array {
        if (! preg_match('/^\d{1,32}$/', $pageId)) {
            throw new InvalidArgumentException('social_invalid_page_id');
        }

        $local = CarbonImmutable::instance($at ?? now())->setTimezone(ProductSelector::TIMEZONE);
        $theme = $this->themeFor($local, $theme);
        $future = $this->futureSlots($local, $slots);
        $created = 0;

        // The two unique constraints also protect concurrent scheduler/admin runs.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $created = DB::transaction(function () use ($pageId, $local, $theme, $future) {
                    $existing = SocialPost::query()->where('page_id', $pageId)
                        ->where('scheduled_date', $local->toDateString())->lockForUpdate()->get();
                    $occupied = $existing->map(fn (SocialPost $post) => $post->scheduled_for->utc()->format('Y-m-d H:i:s'))->all();
                    $missing = array_values(array_filter($future, fn (CarbonImmutable $slot) => ! in_array($slot->format('Y-m-d H:i:s'), $occupied, true)));
                    // A settings change must not create another day's allowance.
                    $missing = array_slice($missing, 0, max(0, self::DAILY_LIMIT - $existing->count()));
                    $snapshots = $this->selector->select(
                        $pageId,
                        $local,
                        count($missing),
                        $existing->pluck('product_id')->all(),
                        $existing->map(fn (SocialPost $post) => $post->snapshot['category_id'] ?? 0)->all(),
                    );

                    foreach ($snapshots as $index => $snapshot) {
                        SocialPost::create([
                            'page_id' => $pageId,
                            'product_id' => $snapshot['product_id'],
                            'scheduled_for' => $missing[$index],
                            'scheduled_date' => $local->toDateString(),
                            'status' => SocialPost::STATUS_DRAFT,
                            'theme' => $theme,
                            'snapshot' => $snapshot,
                        ]);
                    }

                    return count($snapshots);
                }, 3);

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        $posts = SocialPost::query()->where('page_id', $pageId)
            ->where('scheduled_date', $local->toDateString())->orderBy('scheduled_for')->get();
        $occupied = $posts->map(fn (SocialPost $post) => $post->scheduled_for->utc()->format('Y-m-d H:i:s'))->all();
        $shortfall = min(
            max(0, self::DAILY_LIMIT - $posts->count()),
            count(array_filter($future, fn (CarbonImmutable $slot) => ! in_array($slot->format('Y-m-d H:i:s'), $occupied, true))),
        );

        return ['posts' => $posts, 'created' => $created, 'available_slots' => count($future), 'shortfall' => $shortfall];
    }

    public function themeFor(CarbonInterface $date, string $requested = 'auto'): string
    {
        if (! in_array($requested, self::THEMES, true)) {
            throw new InvalidArgumentException('social_invalid_theme');
        }

        if ($requested !== 'auto') {
            return $requested;
        }

        $local = CarbonImmutable::instance($date)->setTimezone(ProductSelector::TIMEZONE);

        return match (true) {
            $local->month === 11 => 'black_friday',
            $local->month === 12 || ($local->month === 1 && $local->day <= 14) => 'new_year',
            default => 'evergreen',
        };
    }

    protected function futureSlots(CarbonImmutable $local, array $slots): array
    {
        if (count($slots) !== self::DAILY_LIMIT) {
            throw new InvalidArgumentException('social_invalid_slots');
        }

        $future = [];

        foreach ($slots as $time) {
            if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('social_invalid_slots');
            }

            $slot = $local->startOfDay()->setTimeFromTimeString($time);

            if ($slot->greaterThan($local)) {
                $future[] = $slot->utc();
            }
        }

        if (count(array_unique($slots)) !== self::DAILY_LIMIT) {
            throw new InvalidArgumentException('social_invalid_slots');
        }

        usort($future, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $future;
    }
}
