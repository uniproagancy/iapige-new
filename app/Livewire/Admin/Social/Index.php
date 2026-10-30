<?php

namespace App\Livewire\Admin\Social;

use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Services\Social\BusinessSuitePreparer;
use App\Services\Social\MultiChannelPublisher;
use App\Services\Social\PostPlanner;
use App\Services\Social\PostPreparer;
use App\Services\Social\SocialSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    public bool $enabled = false;

    public string $theme = 'auto';

    public array $postingTimes = [];

    public array $artwork = ['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => ''];

    public string $manualSearch = '';

    public ?int $manualProductId = null;

    public array $channels = SocialSettings::DEFAULT_CHANNELS;

    #[Locked]
    public string $manualRequestKey = '';

    #[Locked]
    public ?int $manualRequestProductId = null;

    #[Locked]
    public ?array $manualOutcome = null;

    #[Locked]
    public ?array $suiteBundle = null;

    #[Locked]
    public ?array $preparation = null;

    #[Locked]
    public ?array $regeneration = null;

    public function mount(): void
    {
        Gate::authorize('owner');

        $this->loadConfig();
    }

    public function saveConfig(): void
    {
        Gate::authorize('owner');

        $data = $this->validate([
            'enabled' => ['required', 'boolean'],
            'theme' => ['required', Rule::in(['auto', 'evergreen', 'black_friday', 'new_year'])],
            'postingTimes' => ['required', 'array', 'list', 'size:5'],
            'postingTimes.*' => ['required', 'string', 'date_format:H:i', 'distinct'],
            'channels' => ['required', 'array:facebook_feed,instagram_feed,facebook_story,instagram_story', 'size:4'],
            'channels.*' => ['required', 'boolean'],
            'artwork' => ['required', 'array:accent_color,headline,cta,tagline'],
            'artwork.accent_color' => ['nullable', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/D'],
            'artwork.headline' => ['nullable', 'string', 'max:70', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'artwork.cta' => ['nullable', 'string', 'max:28', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'artwork.tagline' => ['nullable', 'string', 'max:45', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
        ], [], [
            'enabled' => __('social.automation'),
            'theme' => __('social.theme'),
            'postingTimes' => __('social.schedule'),
            'postingTimes.*' => __('social.post_time'),
            'channels' => __('social.channels.title'),
            'channels.*' => __('social.channels.destination'),
            'artwork' => __('social.design.title'),
            'artwork.accent_color' => __('social.design.accent_color'),
            'artwork.headline' => __('social.design.headline'),
            'artwork.cta' => __('social.design.cta'),
            'artwork.tagline' => __('social.design.tagline'),
        ]);

        $settings = app(SocialSettings::class);
        $previous = $settings->values();
        $status = $settings->status();
        foreach ($data['channels'] as $channel => $enabled) {
            if ($enabled && ! $previous['channels'][$channel] && ! ($status['channel_status'][$channel]['connected'] ?? false)) {
                $this->addError('channels.'.$channel, __('social.channels.verify_before_enable'));
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $settings->save([
            'enabled' => $data['enabled'],
            'theme' => $data['theme'],
            'slots' => $data['postingTimes'],
            'artwork' => $data['artwork'],
            'channels' => $data['channels'],
        ]);
        $this->loadConfig();
        $this->preparation = null;
        $this->regeneration = null;
        $this->dispatch('toast', message: __('social.saved'));
    }

    public function resetArtwork(): void
    {
        Gate::authorize('owner');

        $this->artwork = ['accent_color' => '', 'headline' => '', 'cta' => '', 'tagline' => ''];
        $this->resetErrorBag(['artwork', 'artwork.*']);
    }

    public function updatedManualSearch(): void
    {
        Gate::authorize('owner');

        $this->manualProductId = null;
        $this->suiteBundle = null;
        $this->resetErrorBag(['manualProductId', 'suiteBundle']);
    }

    public function selectManualProduct(int $id): void
    {
        Gate::authorize('owner');

        $this->suiteBundle = null;
        $this->resetErrorBag(['manualProductId', 'suiteBundle']);
        if (! $this->manualProductQuery()->whereKey($id)->exists()) {
            $this->manualProductId = null;
            $this->addError('manualProductId', __('social.suite.product_unavailable'));

            return;
        }

        $this->manualProductId = $id;
        $this->manualSearch = '';
        if ($this->manualRequestProductId !== $id) {
            $this->manualRequestKey = bin2hex(random_bytes(32));
            $this->manualRequestProductId = $id;
            $this->manualOutcome = null;
        }
    }

    public function newManualRequest(): void
    {
        Gate::authorize('owner');

        if ($this->manualOutcome && (($this->manualOutcome['unknown'] ?? 0) > 0
            || collect($this->manualOutcome['publications'])->contains(fn ($publication) => in_array($publication['status'], ['draft', 'ready', 'pending', 'publishing', 'unknown'], true)))) {
            $this->addError('manualPublishing', __('social.manual.unknown_hint'));

            return;
        }

        if (! $this->manualProductId || ! $this->manualProductQuery()->whereKey($this->manualProductId)->exists()) {
            $this->addError('manualProductId', __('social.suite.product_unavailable'));

            return;
        }
        $this->manualRequestKey = bin2hex(random_bytes(32));
        $this->manualRequestProductId = $this->manualProductId;
        $this->manualOutcome = null;
    }

    public function publishNow(): void
    {
        Gate::authorize('owner');

        $this->resetErrorBag('manualPublishing');
        if (! $this->manualProductId || $this->manualRequestProductId !== $this->manualProductId
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $this->manualRequestKey)) {
            $this->addError('manualProductId', __('social.manual.select_product'));

            return;
        }

        try {
            $channels = array_keys(array_filter(app(SocialSettings::class)->values()['channels']));
            if ($channels === []) {
                $this->addError('manualPublishing', __('social.manual.no_channels'));

                return;
            }
            $result = app(MultiChannelPublisher::class)->publishProduct($this->manualProductId,
                (int) Auth::id(), $this->manualRequestKey, $channels);
            $publications = [];
            foreach ($result['publications'] ?? [] as $publication) {
                if (! is_array($publication) || ! in_array($publication['channel'] ?? '', $channels, true)) {
                    continue;
                }
                $status = in_array($publication['status'] ?? '', ['draft', 'pending', 'ready', 'publishing', 'published', 'unknown', 'blocked', 'failed', 'skipped', 'disabled'], true)
                    ? $publication['status'] : 'unknown';
                $publications[$publication['channel']] = ['status' => $status,
                    'permalink' => $status === 'published' ? $this->publicPermalink($publication['permalink'] ?? null) : null,
                    'has_issue' => filled($publication['error'] ?? null)];
            }
            $this->manualOutcome = ['published' => max(0, min(4, (int) ($result['published'] ?? 0))),
                'unknown' => max(0, min(4, (int) ($result['unknown'] ?? 0))),
                'blocked' => max(0, min(4, (int) ($result['blocked'] ?? 0))),
                'publications' => $publications];
        } catch (Throwable $e) {
            report($e);
            $this->addError('manualPublishing', __('social.manual.publish_failed'));
        }
    }

    public function prepareForSuite(): void
    {
        Gate::authorize('owner');

        $this->suiteBundle = null;
        $this->resetErrorBag('suiteBundle');
        $this->validate(['manualProductId' => ['required', 'integer', 'min:1',
            Rule::exists('products', 'id')->whereNull('deleted_at')]], [], [
                'manualProductId' => __('social.suite.product'),
            ]);

        try {
            $this->suiteBundle = app(BusinessSuitePreparer::class)->prepareProduct($this->manualProductId, (int) Auth::id());
            $this->dispatch('suite-bundle-ready');
        } catch (Throwable $e) {
            report($e);
            $this->addError('suiteBundle', __('social.suite.prepare_failed'));
        }
    }

    public function preparePostForSuite(int $postId): void
    {
        Gate::authorize('owner');

        $this->suiteBundle = null;
        $this->resetErrorBag('suiteBundle');

        try {
            $this->suiteBundle = app(BusinessSuitePreparer::class)->preparePost($postId, (int) Auth::id());
            $this->manualProductId = null;
            $this->manualSearch = '';
            $this->dispatch('suite-bundle-ready');
        } catch (Throwable $e) {
            report($e);
            $this->addError('suiteBundle', __('social.suite.prepare_failed'));
        }
    }

    public function prepareToday(): void
    {
        Gate::authorize('owner');

        $this->resetErrorBag('preparation');
        $this->preparation = null;
        $this->regeneration = null;
        $values = app(SocialSettings::class)->values();

        try {
            $plan = app(PostPlanner::class)->plan(
                $values['page_id'],
                theme: $values['theme'],
                slots: $values['slots'],
            );

            $prepared = 0;

            foreach ($plan['posts'] as $post) {
                if ($this->prepareFuturePost($post->id, $values)) {
                    $prepared++;
                }
            }

            $this->preparation = [
                'prepared' => $prepared,
                'shortfall' => (int) $plan['shortfall'],
                'available_slots' => (int) $plan['available_slots'],
            ];
        } catch (Throwable $e) {
            report($e);
            $this->addError('preparation', __('social.prepare_failed'));
        }
    }

    public function regenerateToday(): void
    {
        Gate::authorize('owner');

        $this->resetErrorBag('regeneration');
        $this->preparation = null;
        $this->regeneration = null;
        $values = app(SocialSettings::class)->values();

        try {
            $ids = SocialPost::query()->where('page_id', SocialSettings::PAGE_ID)
                ->whereDate('scheduled_date', now('Asia/Tbilisi')->toDateString())
                ->where('scheduled_for', '>', now('UTC'))
                ->whereIn('status', ['draft', 'ready'])
                ->orderBy('scheduled_for')->pluck('id');
            $prepared = 0;
            $failed = 0;

            foreach ($ids as $id) {
                try {
                    if ($this->prepareFuturePost((int) $id, $values, regenerate: true)) {
                        $prepared++;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }

            $this->regeneration = ['prepared' => $prepared, 'failed' => $failed];
        } catch (Throwable $e) {
            report($e);
            $this->addError('regeneration', __('social.regenerate_failed'));
        }
    }

    public function refreshConnection(): void
    {
        Gate::authorize('owner');

        $this->resetErrorBag('connection');

        try {
            app(MultiChannelPublisher::class)->connectionStatus();
            $this->dispatch('toast', message: __('social.connection_refreshed'));
        } catch (Throwable $e) {
            report($e);
            $this->addError('connection', __('social.connection.connection_failed'));
        }
    }

    public function render()
    {
        Gate::authorize('owner');

        $settings = app(SocialSettings::class);
        $values = $settings->values();
        $connection = $settings->status();
        $allowedReasons = [
            'connection_missing', 'oauth_unavailable', 'wrong_page', 'permission_missing',
            'token_expired', 'connection_failed', 'connected',
        ];
        $reason = in_array($connection['reason'] ?? '', $allowedReasons, true)
            ? $connection['reason']
            : 'connection_failed';

        $posts = SocialPost::query()
            ->where('page_id', $values['page_id'])
            ->with('publications')
            ->orderByDesc('scheduled_for')
            ->limit(20)
            ->get();
        $ledgerChannels = [];
        foreach ($posts as $post) {
            $publications = $post->publications->keyBy('channel');
            foreach (SocialSettings::CHANNELS as $channel) {
                $publication = $publications->get($channel);
                $fallback = $channel === 'facebook_feed' ? $post->status
                    : (! $values['channels'][$channel] ? 'disabled'
                        : (in_array($post->status, ['draft', 'ready'], true) ? 'pending' : 'skipped'));
                $channelStatus = $publication?->status ?? $fallback;
                $channelStatus = in_array($channelStatus, ['draft', 'pending', 'ready', 'publishing', 'published', 'unknown', 'blocked', 'failed', 'skipped', 'disabled'], true)
                    ? $channelStatus : 'unknown';
                $link = $this->publicPermalink($publication?->permalink);
                if (! $publication && $channel === 'facebook_feed' && $post->status === 'published'
                    && preg_match('/\A'.preg_quote(SocialSettings::PAGE_ID, '/').'_[0-9]+\z/D', (string) $post->post_id)) {
                    $link = 'https://www.facebook.com/'.$post->post_id;
                }
                $ledgerChannels[$post->id][$channel] = ['status' => $channelStatus,
                    'permalink' => $channelStatus === 'published' ? $link : null,
                    'published_at' => ($publication?->published_at ?? ($channel === 'facebook_feed' ? $post->published_at : null))
                        ?->setTimezone('Asia/Tbilisi')->format('d.m.Y H:i'),
                    'has_issue' => filled($publication?->error)];
            }
        }

        $today = SocialPost::query()
            ->where('page_id', $values['page_id'])
            ->whereDate('scheduled_date', now('Asia/Tbilisi')->toDateString())
            ->get(['status']);
        $presetTheme = app(PostPlanner::class)->themeFor(now('UTC'),
            in_array($this->theme, PostPlanner::THEMES, true) ? $this->theme : 'auto');

        return view('livewire.admin.social.index', [
            'values' => $values,
            'connection' => [
                'connected' => (bool) ($connection['connected'] ?? false),
                'oauth_ready' => (bool) ($connection['oauth_ready'] ?? false),
                'reason' => $reason,
                'last_checked' => $connection['last_checked'] ?? null,
                'instagram' => $connection['instagram'],
                'channel_status' => $connection['channel_status'],
            ],
            'posts' => $posts,
            'ledgerChannels' => $ledgerChannels,
            'manualHistory' => $this->manualHistory(),
            'todayCount' => $today->count(),
            'publishedCount' => $today->where('status', 'published')->count(),
            'readyCount' => $today->where('status', 'ready')->count(),
            'presetTheme' => $presetTheme,
            'presetAccent' => match ($presetTheme) {
                'black_friday' => '#FF751F',
                'new_year' => '#E8C571',
                default => '#F5752A',
            },
            'manualMatches' => $this->manualMatches(),
            'manualSelectedProduct' => $this->manualProductId
                ? $this->manualProductQuery()->find($this->manualProductId) : null,
        ])->layout('layouts.admin', ['title' => __('social.title')]);
    }

    protected function loadConfig(): void
    {
        $values = app(SocialSettings::class)->values();
        $this->enabled = (bool) $values['enabled'];
        $this->theme = $values['theme'];
        $this->postingTimes = $values['slots'];
        $this->artwork = $values['artwork'];
        $this->channels = $values['channels'];
    }

    protected function prepareFuturePost(int $id, array $values, bool $regenerate = false): bool
    {
        return DB::transaction(function () use ($id, $values, $regenerate) {
            $post = SocialPost::query()->where('page_id', SocialSettings::PAGE_ID)
                ->whereKey($id)->lockForUpdate()->first();
            $today = now('Asia/Tbilisi')->toDateString();

            // Reread under the same row lock used by the publisher, including
            // the clock check after waiting, so a preview cannot undo its claim.
            if (! $post || ! in_array($post->status, $regenerate ? ['draft', 'ready'] : ['draft'], true)
                || $post->getRawOriginal('scheduled_date') !== $today
                || $post->scheduled_for->setTimezone('Asia/Tbilisi')->toDateString() !== $today
                || $post->scheduled_for->lessThanOrEqualTo(now('UTC'))) {
                return false;
            }
            if ($regenerate) {
                $post->theme = app(PostPlanner::class)->themeFor($post->scheduled_for, $values['theme']);
            }

            return app(PostPreparer::class)->prepare($post)->status === 'ready';
        }, 3);
    }

    protected function manualProductQuery(): Builder
    {
        return Product::query()->active()->where('stock', '>', 0)->where('is_preorder', false)
            ->where('price', '>', 0)->withTranslation('ka')->with(['brand', 'images', 'livePromotions']);
    }

    protected function manualHistory(): array
    {
        $requests = $this->manualHistoryQuery()->select('request_key', 'product_id')
            ->selectRaw('MAX(id) as latest_id')->groupBy('request_key', 'product_id')
            ->orderByDesc('latest_id')->limit(10)->get();
        if ($requests->isEmpty()) {
            return [];
        }
        $groups = $this->manualHistoryQuery()->whereIn('request_key', $requests->pluck('request_key'))
            ->orderByDesc('id')->get(['id', 'request_key', 'product_id', 'channel', 'status', 'snapshot',
                'permalink', 'error', 'created_at', 'published_at'])
            ->groupBy(fn (SocialPublication $row) => $row->request_key.'|'.$row->product_id);
        $history = [];
        foreach ($requests as $request) {
            $rows = $groups->get($request->request_key.'|'.$request->product_id);
            $first = $rows?->first();
            if (! $first) {
                continue;
            }
            $named = $rows->first(fn (SocialPublication $row) => filled($row->snapshot['title'] ?? null)) ?? $first;
            $channels = [];
            $byChannel = $rows->keyBy('channel');
            foreach (SocialSettings::CHANNELS as $channel) {
                $publication = $byChannel->get($channel);
                $status = $publication?->status ?? 'disabled';
                $status = in_array($status, ['draft', 'pending', 'ready', 'publishing', 'published', 'unknown', 'blocked', 'failed', 'skipped', 'disabled'], true)
                    ? $status : 'unknown';
                $channels[$channel] = ['status' => $status,
                    'permalink' => $status === 'published' ? $this->publicPermalink($publication?->permalink) : null,
                    'published_at' => $publication?->published_at?->setTimezone('Asia/Tbilisi')->format('d.m.Y H:i'),
                    'has_issue' => filled($publication?->error)];
            }
            $history[] = ['id' => $first->id, 'product_id' => $first->product_id,
                'title' => $named->snapshot['title'] ?? __('social.product_id', ['id' => $first->product_id]),
                'created_at' => $first->created_at?->setTimezone('Asia/Tbilisi')->format('d.m.Y H:i'),
                'channels' => $channels];
        }

        return $history;
    }

    protected function manualHistoryQuery(): Builder
    {
        return SocialPublication::query()->where('origin', SocialPublication::ORIGIN_MANUAL)
            ->where(fn (Builder $query) => $query->whereNull('error')->orWhere('error', '!=', 'private_preview_only'));
    }

    protected function publicPermalink(mixed $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! in_array(strtolower($parts['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'instagram.com', 'www.instagram.com'], true)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        foreach ($query as $key => $value) {
            if (! in_array($key, ['id', 'story_fbid'], true) || ! is_string($value) || ! ctype_digit($value)) {
                return null;
            }
        }

        return $url;
    }

    protected function manualMatches()
    {
        $term = mb_substr(trim($this->manualSearch), 0, 100);
        if (mb_strlen($term) < 2 && ! ctype_digit($term)) {
            return collect();
        }

        return $this->manualProductQuery()->where(function (Builder $query) use ($term) {
            $query->where('sku', 'like', '%'.$term.'%')
                ->orWhereHas('translations', fn ($translation) => $translation->where('name', 'like', '%'.$term.'%'));
            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        })->orderByDesc('id')->limit(8)->get();
    }
}
