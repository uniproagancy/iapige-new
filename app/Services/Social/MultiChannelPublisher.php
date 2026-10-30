<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\SocialPublication;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MultiChannelPublisher
{
    public function __construct(
        protected SocialSettings $settings,
        protected ProductSelector $selector,
        protected PostPlanner $planner,
        protected PostPreparer $preparer,
        protected PagePublisher $facebook,
        protected MetaChannelPublisher $channels,
    ) {}

    /** An explicit owner check; render paths read the stored status only. */
    public function connectionStatus(): array
    {
        $this->facebook->connectionStatus();

        return $this->channels->connectionStatus();
    }

    public function publishProduct(int $productId, int $ownerId, string $idempotencyKey, ?array $channels = null): array
    {
        if ($ownerId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/D', $idempotencyKey)) {
            throw new DomainException('invalid_request');
        }
        $channels ??= SocialPublication::CHANNELS;
        if (! array_is_list($channels) || $channels === [] || count($channels) !== count(array_unique($channels))
            || array_diff($channels, SocialPublication::CHANNELS)) {
            throw new DomainException('invalid_request');
        }
        $request = hash('sha256', 'manual|'.$ownerId.'|'.$idempotencyKey);
        $request = DB::transaction(function () use ($request, $channels, $productId, $ownerId) {
            $product = Product::withTranslation('ka')->with(['brand', 'images', 'livePromotions'])
                ->whereKey($productId)->lockForUpdate()->first();
            $existing = SocialPublication::query()->where('request_key', $request)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn ($row) => $row->product_id !== $productId || $row->owner_id !== $ownerId)
                    || $existing->pluck('channel')->sort()->values()->all() !== collect($channels)->sort()->values()->all()) {
                    throw new DomainException('request_conflict');
                }

                return $request;
            }
            SocialPublication::query()->where('origin', 'manual')->where('owner_id', $ownerId)->where('product_id', $productId)
                ->whereIn('status', ['draft', 'ready', 'blocked'])->where('scheduled_for', '<', CarbonImmutable::now('UTC')->subHours(2))
                ->update(['status' => 'skipped', 'error' => 'request_expired']);
            $pending = SocialPublication::query()->where('origin', 'manual')->where('owner_id', $ownerId)->where('product_id', $productId)
                ->whereIn('status', ['draft', 'ready', 'blocked', 'publishing', 'unknown'])->orderBy('id')->first();
            if ($pending !== null) {
                return $pending->request_key;
            }
            if ($product === null || $this->selector->manualSnapshot($product) === null) {
                throw new DomainException('product_unavailable');
            }
            foreach ($channels as $channel) {
                SocialPublication::firstOrCreate(['request_key' => $request, 'channel' => $channel], [
                    'origin' => 'manual', 'product_id' => $productId, 'owner_id' => $ownerId,
                    'account_id' => str_starts_with($channel, 'facebook_') ? SocialSettings::PAGE_ID : null,
                    'scheduled_for' => CarbonImmutable::now('UTC'), 'status' => 'draft',
                ]);
            }

            return $request;
        }, 3);
        $this->connectionStatus();
        $this->process(SocialPublication::query()->where('request_key', $request)->orderBy('id')->get());

        return $this->result(SocialPublication::query()->where('request_key', $request)->orderBy('id')->get(), $request);
    }

    /** The server scheduler also resumes already requested manual containers. */
    public function run(): array
    {
        $values = $this->settings->values();
        $posts = new Collection;
        if ($values['enabled']) {
            $this->facebook->reconcileUnknown();
            $plan = $this->planner->plan($values['page_id'], theme: $values['theme'], slots: $values['slots']);
            $posts = $plan['posts'];
            $this->ensureAutomatic($posts, $values);
            if ($values['channels']['facebook_feed'] ?? true) {
                foreach ($posts as $post) {
                    if ($post->status === 'draft' && $post->scheduled_for->isFuture()) {
                        try {
                            $this->preparer->prepare($post);
                        } catch (\Throwable) {
                            $post->forceFill(['status' => 'skipped', 'error' => 'preparation_failed'])->save();
                        }
                    }
                }
                $this->facebook->publishDue();
            }
            $this->mirrorFacebook($posts->fresh(), $values);
        }
        $this->facebook->reconcileManualUnknown();
        $this->channels->reconcileUnknown();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone(ProductSelector::TIMEZONE)->toDateString();
        $automatic = SocialPublication::query()->where('origin', 'automatic')
            ->whereIn('channel', ['instagram_feed', 'facebook_story', 'instagram_story'])
            ->whereHas('post', fn ($query) => $query->where('scheduled_date', $today))
            ->where('scheduled_for', '<=', $now)->whereIn('status', ['draft', 'ready', 'blocked'])->get();
        $automatic = $values['enabled'] ? $automatic->filter(fn ($row) => $values['channels'][$row->channel] ?? false) : new Collection;
        $manual = SocialPublication::query()->where('origin', 'manual')->whereIn('status', ['draft', 'ready', 'blocked'])
            ->where('scheduled_for', '<=', $now)->orderBy('id')->limit(100)->get();
        if ($automatic->isNotEmpty() || $manual->isNotEmpty()) {
            $this->connectionStatus();
            $this->process($automatic->concat($manual));
        }

        return $this->result($automatic->concat($manual)->map->fresh()->values());
    }

    protected function ensureAutomatic(Collection $posts, array $values): void
    {
        foreach ($posts as $post) {
            foreach (['instagram_feed', 'facebook_story', 'instagram_story'] as $channel) {
                if (! ($values['channels'][$channel] ?? false)) {
                    continue;
                }
                $request = hash('sha256', 'automatic|'.$post->id);
                if (SocialPublication::query()->where('request_key', $request)->where('channel', $channel)->exists()) {
                    continue;
                }
                $enabledAt = $values['channel_enabled_at'][$channel] ?? null;
                $threshold = $enabledAt === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($enabledAt)->utc();
                if (in_array($post->status, ['published', 'publishing', 'unknown'], true)
                    || $post->scheduled_for->lessThan($threshold)) {
                    continue;
                }
                SocialPublication::firstOrCreate(['request_key' => $request, 'channel' => $channel], [
                    'social_post_id' => $post->id, 'origin' => 'automatic', 'product_id' => $post->product_id,
                    'scheduled_for' => $post->scheduled_for,
                    'account_id' => $channel === 'facebook_story' ? SocialSettings::PAGE_ID : null, 'status' => 'draft',
                ]);
            }
        }
        $this->mirrorFacebook($posts, $values);
    }

    protected function mirrorFacebook(Collection $posts, array $values): void
    {
        foreach ($posts as $post) {
            if (! ($values['channels']['facebook_feed'] ?? true) && ! in_array($post->status, ['published', 'publishing', 'unknown'], true)) {
                continue;
            }
            $permalink = $post->status === 'published' && is_string($post->post_id)
                && preg_match('/\A'.preg_quote(SocialSettings::PAGE_ID, '/').'_[0-9]+\z/D', $post->post_id)
                    ? 'https://www.facebook.com/'.$post->post_id : null;
            SocialPublication::updateOrCreate(['request_key' => hash('sha256', 'automatic|'.$post->id), 'channel' => 'facebook_feed'], [
                'social_post_id' => $post->id, 'origin' => 'automatic', 'product_id' => $post->product_id,
                'account_id' => SocialSettings::PAGE_ID, 'scheduled_for' => $post->scheduled_for,
                'status' => $post->status, 'snapshot' => $post->snapshot, 'caption' => $post->caption,
                'external_id' => $post->post_id, 'permalink' => $permalink, 'error' => $post->error,
                'claimed_at' => $post->claimed_at, 'published_at' => $post->published_at,
            ]);
        }
    }

    protected function process(iterable $publications): void
    {
        foreach ($publications as $candidate) {
            if ($candidate->origin === 'manual' && $candidate->scheduled_for->lessThan(CarbonImmutable::now('UTC')->subHours(2))) {
                SocialPublication::query()->whereKey($candidate->id)->whereIn('status', ['draft', 'ready', 'blocked'])
                    ->update(['status' => 'skipped', 'error' => 'request_expired']);

                continue;
            }
            $publication = DB::transaction(function () use ($candidate) {
                $row = SocialPublication::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if ($row === null || ! in_array($row->status, ['draft', 'ready', 'blocked'], true)
                    || $row->scheduled_for->isFuture()) {
                    return null;
                }
                if ($row->origin === 'automatic') {
                    $date = $row->post?->scheduled_date;
                    if ($date === null) {
                        return null;
                    }
                    $day = SocialPublication::query()->where('origin', 'automatic')->where('channel', $row->channel)
                        ->whereHas('post', fn ($query) => $query->where('scheduled_date', $date))
                        ->orderBy('id')->lockForUpdate()->get();
                    if ($day->whereIn('status', ['published', 'publishing', 'unknown'])->count() >= PostPlanner::DAILY_LIMIT) {
                        return null;
                    }
                }
                $row->forceFill(['status' => 'publishing', 'phase' => $row->container_id === null ? 'preparing' : 'container_ready',
                    'claimed_at' => CarbonImmutable::now('UTC'), 'error' => null])->save();

                return $row;
            }, 3);
            if ($publication === null) {
                continue;
            }
            try {
                if ($publication->channel === 'facebook_feed') {
                    $status = $this->settings->status()['channel_status']['facebook_feed'] ?? [];
                    if (! ($status['connected'] ?? false)) {
                        $publication->forceFill(['status' => 'blocked', 'phase' => null, 'error' => $status['reason'] ?? 'connection_missing'])->save();
                    } elseif ($publication->origin === 'manual') {
                        $this->facebook->publishManual($publication);
                    }
                } else {
                    $this->channels->publish($publication);
                }
            } catch (\Throwable) {
                $publication->refresh();
                if ($publication->status === 'publishing') {
                    $publication->forceFill([
                        'status' => in_array($publication->phase, ['uploading', 'creating_container', 'publishing', 'verifying'], true) ? 'unknown' : 'failed',
                        'error' => 'publication_unknown',
                    ])->save();
                }
            }
        }
    }

    protected function result(iterable $publications, ?string $request = null): array
    {
        $rows = collect($publications)->map(fn ($row) => [
            'id' => $row->id, 'channel' => $row->channel, 'status' => $row->status, 'error' => $row->error,
            'external_id' => $row->external_id, 'permalink' => $row->permalink, 'published_at' => $row->published_at?->toIso8601String(),
        ])->values();

        return [
            'request_key' => $request, 'published' => $rows->where('status', 'published')->count(),
            'unknown' => $rows->where('status', 'unknown')->count(), 'blocked' => $rows->where('status', 'blocked')->count(),
            'publications' => $rows->all(),
        ];
    }
}
