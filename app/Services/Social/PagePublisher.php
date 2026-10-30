<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPublication;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PagePublisher
{
    private const API = 'https://graph.facebook.com/v26.0';

    private const REQUIRED_PERMISSIONS = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

    public function __construct(
        protected SocialSettings $settings,
        protected PostPreparer $preparer,
        protected ?PublicationPreparer $publicationPreparer = null,
    ) {}

    /** Network checks run only on explicit owner action or a publishing job. */
    public function connectionStatus(): array
    {
        $token = $this->settings->token();
        if ($token === null) {
            $this->settings->recordStatus(false, 'connection_missing');

            return $this->settings->status();
        }

        try {
            $page = $this->identity($token);
            $this->settings->recordStatus(true, 'connected', $page['name']);
        } catch (RuntimeException $exception) {
            $this->settings->recordStatus(false, $exception->getMessage());
        }

        return $this->settings->status();
    }

    /** The OAuth callback has already checked the owner, consent and CSRF state. */
    public function connectUserToken(string $userToken): void
    {
        try {
            $this->connect($userToken);
        } catch (RuntimeException $exception) {
            $reason = in_array($exception->getMessage(), ['connection_missing', 'oauth_unavailable', 'wrong_page', 'permission_missing', 'token_expired', 'connection_failed'], true)
                ? $exception->getMessage() : 'connection_failed';
            $this->settings->recordStatus(false, $reason);

            // Never chain an HTTP exception: its URL may contain OAuth credentials.
            throw new RuntimeException($reason);
        } catch (Throwable) {
            $this->settings->recordStatus(false, 'connection_failed');
            throw new RuntimeException('connection_failed');
        }
    }

    /** Accepts a Page token only from the protected owner form, never a Pixel credential. */
    public function connectPageToken(string $token): void
    {
        try {
            $identity = $this->identity($token);
            $this->settings->storeConnection($token, $identity['id'], $identity['name']);
        } catch (RuntimeException $exception) {
            $reason = in_array($exception->getMessage(), ['connection_missing', 'wrong_page', 'permission_missing', 'token_expired', 'connection_failed'], true)
                ? $exception->getMessage() : 'connection_failed';
            $this->settings->recordStatus(false, $reason);
            throw new RuntimeException($reason);
        } catch (Throwable) {
            $this->settings->recordStatus(false, 'connection_failed');
            throw new RuntimeException('connection_failed');
        }
    }

    public function publishDue(int $limit = 5): int
    {
        if (! ($this->settings->values()['enabled'] ?? false) || $limit < 1) {
            return 0;
        }
        $token = $this->publishingToken();
        if ($token === null) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone(ProductSelector::TIMEZONE)->toDateString();
        $posts = DB::transaction(function () use ($now, $today, $limit) {
            // Lock the whole day's set so two workers cannot each reserve the daily quota.
            $day = SocialPost::query()->where('page_id', SocialSettings::PAGE_ID)
                ->where('scheduled_date', $today)->orderBy('scheduled_for')->lockForUpdate()->get();
            $occupied = $day->whereIn('status', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_PUBLISHING, SocialPost::STATUS_UNKNOWN])->count();
            $remaining = min(5, $limit, max(0, 5 - $occupied));
            $due = $day->filter(fn (SocialPost $post) => in_array($post->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_READY], true)
                && $post->scheduled_for->lessThanOrEqualTo($now))->take($remaining);
            foreach ($due as $post) {
                $post->forceFill(['status' => SocialPost::STATUS_PUBLISHING, 'claimed_at' => $now, 'error' => null])->save();
            }

            return $due;
        }, 3);

        $published = 0;
        foreach ($posts as $post) {
            if ($this->publish($post, $token)) {
                $published++;
            }
        }

        return $published;
    }

    /** A protected owner action has already claimed this one manual publication. */
    public function publishManual(SocialPublication $publication): bool
    {
        $claimed = SocialPublication::query()->whereKey($publication->getKey())
            ->where('origin', SocialPublication::ORIGIN_MANUAL)->where('channel', 'facebook_feed')
            ->where('account_id', SocialSettings::PAGE_ID)->where('status', SocialPublication::STATUS_PUBLISHING)
            ->where('phase', 'preparing')->whereNotNull('claimed_at')->whereNull('external_id')->whereNull('media_id')
            ->update(['phase' => 'publishing']);
        if ($claimed !== 1) {
            return false;
        }
        $publication->refresh();
        $token = $this->publishingToken();
        if ($token === null) {
            $reason = $this->settings->status()['reason'] ?? 'connection_failed';
            $this->manualFailure($publication, in_array($reason, ['connection_missing', 'wrong_page', 'permission_missing', 'token_expired'], true) ? $reason : 'connection_failed');

            return false;
        }

        try {
            $preparer = $this->publicationPreparer ?? app(PublicationPreparer::class);
            $publication = $preparer->prepare($publication);
            $image = $preparer->imagePath($publication);
            if ($image === null || ! is_string($publication->caption) || trim($publication->caption) === ''
                || filesize($image) > 10 * 1024 * 1024 || (@getimagesize($image)[2] ?? null) !== IMAGETYPE_PNG) {
                $this->manualFailure($publication, 'image_unavailable');

                return false;
            }
            $preparer->assertCurrent($publication);
            $target = null;
            if ((new SocialFeedLink($preparer))->enabled()) {
                $target = (new SocialFeedLink($preparer))->target($publication);
                $publication->forceFill(['snapshot' => array_replace($publication->snapshot, ['native_feed_link' => $target])])->save();
            } else {
                $stream = @fopen($image, 'rb');
                if ($stream === false) {
                    $this->manualFailure($publication, 'image_unavailable');

                    return false;
                }
            }
        } catch (DomainException $exception) {
            $unavailable = in_array($exception->getMessage(), ['product_unavailable', 'publication_stale'], true);
            $publication->forceFill(['status' => $unavailable ? SocialPublication::STATUS_SKIPPED : SocialPublication::STATUS_FAILED,
                'phase' => 'failed', 'error' => $unavailable ? $exception->getMessage() : 'preparation_failed'])->save();

            return false;
        } catch (Throwable) {
            $this->manualFailure($publication, 'preparation_failed');

            return false;
        }

        $result = $target === null ? $this->photoResult($token, $stream, $publication->caption, 'iapi-manual-'.$publication->id.'.png')
            : $this->feedResult($token, $publication->caption, $target);
        if ($result['outcome'] !== 'accepted') {
            $publication->forceFill(['media_id' => $result['media_id'],
                'status' => $result['outcome'] === 'unknown' ? SocialPublication::STATUS_UNKNOWN : SocialPublication::STATUS_FAILED,
                'phase' => $result['outcome'] === 'unknown' ? 'unknown' : 'failed', 'error' => $result['error']])->save();

            return false;
        }
        $publication->forceFill(['media_id' => $result['media_id'], 'external_id' => $result['post_id'],
            'status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'verifying', 'error' => 'verification_failed'])->save();

        return $this->verifyManualPublication($publication, $token);
    }

    /** Recover known manual post IDs by GET only, independently of the automatic quota. */
    public function reconcileManualUnknown(): int
    {
        $cursorKey = 'social.publications.facebook_feed.manual.reconcile.cursor';
        SocialPublication::query()->where('origin', SocialPublication::ORIGIN_MANUAL)
            ->where('channel', 'facebook_feed')->where('account_id', SocialSettings::PAGE_ID)
            ->where('status', SocialPublication::STATUS_PUBLISHING)
            ->where('claimed_at', '<', CarbonImmutable::now('UTC')->subMinutes(10))
            ->update(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown', 'error' => 'stale_claim']);
        $query = SocialPublication::query()->where('origin', SocialPublication::ORIGIN_MANUAL)
            ->where('channel', 'facebook_feed')->where('account_id', SocialSettings::PAGE_ID)
            ->where('status', SocialPublication::STATUS_UNKNOWN)->whereNotNull('external_id');
        $publications = $this->reconciliationBatch($query, $cursorKey);
        if ($publications->isEmpty()) {
            return 0;
        }
        $token = $this->publishingToken();
        if ($token === null) {
            return 0;
        }
        $published = 0;
        foreach ($publications as $publication) {
            if ($this->verifyManualPublication($publication, $token)) {
                $published++;
            }
        }
        Cache::forever($cursorKey, (int) $publications->last()->getKey());

        return $published;
    }

    private function verifyManualPublication(SocialPublication $publication, string $token): bool
    {
        $data = $this->confirmedPagePost($publication->external_id, $token, $publication->snapshot['native_feed_link'] ?? null);
        if ($data === null) {
            $publication->forceFill(['status' => SocialPublication::STATUS_UNKNOWN, 'phase' => 'unknown', 'error' => 'verification_failed'])->save();

            return false;
        }
        $publication->forceFill(['status' => SocialPublication::STATUS_PUBLISHED, 'phase' => 'published',
            'permalink' => $this->safePermalink($data['permalink_url'] ?? null),
            'published_at' => CarbonImmutable::now('UTC'), 'error' => null])->save();

        return true;
    }

    /** Ambiguous writes are inspected by ID only; this method never sends a POST. */
    public function reconcileUnknown(): int
    {
        $cursorKey = 'social.posts.facebook_feed.reconcile.cursor';
        if (! ($this->settings->values()['enabled'] ?? false)) {
            return 0;
        }
        SocialPost::query()->where('page_id', SocialSettings::PAGE_ID)->where('status', SocialPost::STATUS_PUBLISHING)
            ->where(function ($query) {
                $cutoff = CarbonImmutable::now('UTC')->subMinutes(10);
                $query->where('claimed_at', '<=', $cutoff)->orWhere(fn ($query) => $query->whereNull('claimed_at')->where('updated_at', '<=', $cutoff));
            })->update(['status' => SocialPost::STATUS_UNKNOWN, 'error' => 'stale_claim']);

        $query = SocialPost::query()->where('page_id', SocialSettings::PAGE_ID)->where('status', SocialPost::STATUS_UNKNOWN)
            ->whereNotNull('post_id');
        $posts = $this->reconciliationBatch($query, $cursorKey);
        if ($posts->isEmpty()) {
            return 0;
        }
        $token = $this->publishingToken();
        if ($token === null) {
            return 0;
        }
        $published = 0;
        foreach ($posts as $post) {
            if ($this->verifyPublication($post, $token)) {
                $published++;
            }
        }
        Cache::forever($cursorKey, (int) $posts->last()->getKey());

        return $published;
    }

    private function reconciliationBatch(Builder $query, string $cursorKey): Collection
    {
        $cursor = max(0, (int) Cache::get($cursorKey, 0));
        $rows = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit(25)->get();

        return $rows->isEmpty() && $cursor > 0 ? $query->orderBy('id')->limit(25)->get() : $rows;
    }

    private function connect(string $userToken): void
    {
        if (! $this->validToken($userToken)) {
            throw new RuntimeException('connection_missing');
        }
        $appId = config('services.facebook.client_id');
        $secret = config('services.facebook.client_secret');
        if (! is_string($appId) || $appId === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('oauth_unavailable');
        }

        $permissions = $this->read($userToken, '/me/permissions');
        $granted = [];
        if (! is_array($permissions['data'] ?? null)) {
            throw new RuntimeException('permission_missing');
        }
        foreach ($permissions['data'] as $permission) {
            if (is_array($permission) && ($permission['status'] ?? null) === 'granted' && is_string($permission['permission'] ?? null)) {
                $granted[] = $permission['permission'];
            }
        }
        if (array_diff(self::REQUIRED_PERMISSIONS, $granted) !== []) {
            throw new RuntimeException('permission_missing');
        }

        $exchange = $this->read($userToken, '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $appId,
            'client_secret' => $secret, 'fb_exchange_token' => $userToken,
        ]);
        $longToken = $exchange['access_token'] ?? null;
        if (! is_string($longToken) || ! $this->validToken($longToken)) {
            throw new RuntimeException('connection_failed');
        }

        $after = null;
        for ($page = 0; $page < 10; $page++) {
            $query = ['fields' => 'id,name,access_token,tasks', 'limit' => 100];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $accounts = $this->read($longToken, '/me/accounts', $query);
            if (! is_array($accounts['data'] ?? null)) {
                throw new RuntimeException('connection_failed');
            }
            foreach ($accounts['data'] as $account) {
                if (! is_array($account) || (string) ($account['id'] ?? '') !== SocialSettings::PAGE_ID) {
                    continue;
                }
                $tasks = $account['tasks'] ?? [];
                if (! is_array($tasks) || array_intersect(['CREATE_CONTENT', 'MANAGE', 'PROFILE_PLUS_CREATE_CONTENT', 'PROFILE_PLUS_MANAGE', 'PROFILE_PLUS_FULL_CONTROL'], $tasks) === []) {
                    throw new RuntimeException('permission_missing');
                }
                $pageToken = $account['access_token'] ?? null;
                if (! is_string($pageToken) || ! $this->validToken($pageToken)) {
                    throw new RuntimeException('connection_failed');
                }
                $identity = $this->identity($pageToken);
                $this->settings->storeConnection($pageToken, SocialSettings::PAGE_ID, $identity['name']);

                return;
            }
            $next = $accounts['paging']['cursors']['after'] ?? null;
            if (empty($accounts['paging']['next']) || ! is_string($next) || $next === '' || strlen($next) > 4096 || $next === $after) {
                break;
            }
            // Follow only Meta's cursor on our fixed endpoint, never a supplied next URL.
            $after = $next;
        }

        throw new RuntimeException('wrong_page');
    }

    private function publishingToken(): ?string
    {
        $token = $this->settings->token();
        if ($token === null) {
            $this->settings->recordStatus(false, 'connection_missing');

            return null;
        }
        try {
            $page = $this->identity($token);
            $this->settings->recordStatus(true, 'connected', $page['name']);

            return $token;
        } catch (RuntimeException $exception) {
            $this->settings->recordStatus(false, $exception->getMessage());

            return null;
        }
    }

    private function publish(SocialPost $post, string $token): bool
    {
        try {
            $post = $this->preparer->prepare($post);
            if (config('social.facebook_native_link', false) === true) {
                return $this->publishNativePost($post, $token);
            }
            $image = $this->preparer->imagePath($post);
            if ($image === null || ! is_string($post->caption) || trim($post->caption) === '' || filesize($image) > 10 * 1024 * 1024
                || (@getimagesize($image)[2] ?? null) !== IMAGETYPE_PNG) {
                $this->fail($post, 'image_unavailable');

                return false;
            }
            $stream = @fopen($image, 'rb');
            if ($stream === false) {
                $this->fail($post, 'image_unavailable');

                return false;
            }
        } catch (DomainException $exception) {
            $post->forceFill(['status' => SocialPost::STATUS_SKIPPED, 'error' => $exception->getMessage() === 'product_unavailable' ? 'product_unavailable' : 'preparation_failed'])->save();

            return false;
        } catch (Throwable) {
            $this->fail($post, 'preparation_failed');

            return false;
        }

        $result = $this->photoResult($token, $stream, $post->caption, 'iapi-'.$post->id.'.png');
        if ($result['outcome'] !== 'accepted') {
            if ($result['outcome'] === 'unknown') {
                $this->unknown($post, $result['error']);
            } else {
                $this->fail($post, $result['error']);
            }

            return false;
        }
        $post->forceFill(['post_id' => $result['post_id'], 'status' => SocialPost::STATUS_UNKNOWN, 'error' => 'verification_failed'])->save();

        return $this->verifyPublication($post, $token);
    }

    private function verifyPublication(SocialPost $post, string $token): bool
    {
        if (! $this->validPostId($post->post_id) || $this->confirmedPagePost($post->post_id, $token, $post->snapshot['native_feed_link'] ?? null) === null) {
            $this->unknown($post, 'verification_failed');

            return false;
        }
        $post->forceFill(['status' => SocialPost::STATUS_PUBLISHED, 'published_at' => CarbonImmutable::now('UTC'), 'error' => null])->save();
        if (isset($post->snapshot['native_feed_link'])) {
            SocialPublication::query()->where('social_post_id', $post->id)->where('product_id', $post->product_id)
                ->where('origin', 'automatic')->where('channel', 'facebook_feed')->where('account_id', SocialSettings::PAGE_ID)
                ->where('external_id', $post->post_id)->update(['status' => 'published', 'phase' => 'published',
                    'published_at' => $post->published_at, 'permalink' => 'https://www.facebook.com/'.$post->post_id, 'error' => null]);
        }

        return true;
    }

    private function publishNativePost(SocialPost $post, string $token): bool
    {
        $publication = SocialPublication::firstOrCreate([
            'request_key' => hash('sha256', 'automatic|'.$post->id), 'channel' => 'facebook_feed',
        ], ['social_post_id' => $post->id, 'origin' => 'automatic', 'product_id' => $post->product_id,
            'account_id' => SocialSettings::PAGE_ID, 'scheduled_for' => $post->scheduled_for, 'status' => 'draft']);
        $claimed = SocialPublication::query()->whereKey($publication->id)->where('origin', 'automatic')
            ->where('social_post_id', $post->id)->where('product_id', $post->product_id)->where('account_id', SocialSettings::PAGE_ID)
            ->whereIn('status', ['draft', 'ready', 'publishing'])->whereNull('external_id')->whereNull('media_id')
            ->where(fn ($query) => $query->whereNull('phase')->orWhere('phase', 'preparing'))
            ->update(['status' => 'publishing', 'phase' => 'publishing', 'claimed_at' => $post->claimed_at]);
        if ($claimed !== 1) {
            $this->unknown($post, 'publication_unknown');

            return false;
        }
        $publication->refresh();
        try {
            $preparer = $this->publicationPreparer ?? app(PublicationPreparer::class);
            $publication = $preparer->prepare($publication);
            if (! is_string($publication->caption) || trim($publication->caption) === '' || $preparer->imagePath($publication) === null) {
                throw new DomainException('image_unavailable');
            }
            $preparer->assertCurrent($publication);
            $target = (new SocialFeedLink($preparer))->target($publication);
            $snapshot = array_replace($publication->snapshot, ['native_feed_link' => $target]);
            $publication->forceFill(['snapshot' => $snapshot])->save();
            $post->forceFill(['snapshot' => $snapshot, 'caption' => $publication->caption])->save();
        } catch (Throwable $exception) {
            $unavailable = $exception instanceof DomainException && in_array($exception->getMessage(), ['product_unavailable', 'publication_stale'], true);
            $error = $unavailable ? $exception->getMessage() : 'preparation_failed';
            $publication->forceFill(['status' => $unavailable ? 'skipped' : 'failed', 'phase' => 'failed', 'error' => $error])->save();
            $post->forceFill(['status' => $unavailable ? 'skipped' : 'failed', 'error' => $error])->save();

            return false;
        }
        $result = $this->feedResult($token, $publication->caption, $target);
        $status = $result['outcome'] === 'failed' ? 'failed' : 'unknown';
        $error = $result['outcome'] === 'accepted' ? 'verification_failed' : $result['error'];
        // Persist both IDs together before attempting verification; neither ledger can retry an ambiguous write.
        DB::transaction(function () use ($post, $publication, $result, $status, $error) {
            $publication->forceFill(['external_id' => $result['post_id'], 'status' => $status,
                'phase' => $result['outcome'] === 'accepted' ? 'verifying' : $status, 'error' => $error])->save();
            $post->forceFill(['post_id' => $result['post_id'], 'status' => $status, 'error' => $error])->save();
        });
        if ($result['outcome'] !== 'accepted') {
            return false;
        }
        $published = $this->verifyPublication($post, $token);
        $publication->forceFill(['status' => $post->status, 'phase' => $published ? 'published' : 'unknown',
            'published_at' => $post->published_at, 'error' => $post->error,
            'permalink' => $published ? 'https://www.facebook.com/'.$post->post_id : null])->save();

        return $published;
    }

    private function feedResult(string $token, string $caption, string $target): array
    {
        $unknown = ['outcome' => 'unknown', 'error' => 'publication_unknown', 'media_id' => null, 'post_id' => null];
        try {
            $response = $this->request($token)->asForm()->timeout(45)->post(self::API.'/'.SocialSettings::PAGE_ID.'/feed', [
                'message' => $caption, 'link' => $target, 'published' => 'true',
                'call_to_action' => json_encode(['type' => 'SHOP_NOW', 'value' => ['link' => $target]], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return $unknown;
        }
        if ($response->serverError()) {
            return $unknown;
        }
        $data = $response->json();
        if ($response->clientError() || (is_array($data) && isset($data['error']))) {
            $reason = $this->errorReason($response, 'publish_rejected');
            if (in_array($reason, ['token_expired', 'permission_missing'], true)) {
                $this->settings->recordStatus(false, $reason);
            }

            return array_replace($unknown, ['outcome' => 'failed', 'error' => $reason]);
        }
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        return $response->successful() && $this->validPostId($id)
            ? array_replace($unknown, ['outcome' => 'accepted', 'error' => null, 'post_id' => (string) $id]) : $unknown;
    }

    private function photoResult(string $token, mixed $stream, string $caption, string $filename): array
    {
        $unknown = ['outcome' => 'unknown', 'error' => 'publication_unknown', 'media_id' => null, 'post_id' => null];
        try {
            // A lost response can still mean a published post. Never retry this write.
            $response = $this->request($token)->timeout(45)->attach('source', $stream, $filename, ['Content-Type' => 'image/png'])
                ->post(self::API.'/'.SocialSettings::PAGE_ID.'/photos', ['caption' => $caption, 'published' => 'true']);
        } catch (Throwable) {
            return $unknown;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($response->serverError()) {
            return $unknown;
        }
        $data = $response->json();
        $mediaId = is_array($data) && $this->validPostId($data['id'] ?? null) ? (string) $data['id'] : null;
        if ($response->clientError() || (is_array($data) && isset($data['error']))) {
            $reason = $this->errorReason($response, 'publish_rejected');
            if (in_array($reason, ['token_expired', 'permission_missing'], true)) {
                $this->settings->recordStatus(false, $reason);
            }

            return ['outcome' => 'failed', 'error' => $reason, 'media_id' => $mediaId, 'post_id' => null];
        }
        $id = is_array($data) ? ($data['post_id'] ?? $data['id'] ?? null) : null;
        if (! $response->successful() || ! $this->validPostId($id)) {
            return array_replace($unknown, ['media_id' => $mediaId]);
        }

        return ['outcome' => 'accepted', 'error' => null, 'media_id' => $mediaId, 'post_id' => (string) $id];
    }

    private function confirmedPagePost(string $id, string $token, mixed $expectedLink = null): ?array
    {
        if (! $this->validPostId($id) || ($expectedLink !== null && (! is_string($expectedLink) || ! filter_var($expectedLink, FILTER_VALIDATE_URL)))) {
            return null;
        }
        try {
            $data = $this->read($token, '/'.$id, ['fields' => 'id,from,is_published,permalink_url'.($expectedLink === null ? '' : ',call_to_action')]);
        } catch (RuntimeException) {
            return null;
        }
        if ($expectedLink !== null && (($data['call_to_action']['type'] ?? null) !== 'SHOP_NOW'
            || ($data['call_to_action']['value']['link'] ?? null) !== $expectedLink)) {
            return null;
        }
        if ((string) ($data['from']['id'] ?? '') !== SocialSettings::PAGE_ID || ($data['is_published'] ?? null) !== true
            || (string) ($data['id'] ?? '') !== $id) {
            return null;
        }

        return $data;
    }

    private function safePermalink(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 500 || preg_match('/[\r\n\x00]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array(strtolower($parts['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch'], true)) {
            return null;
        }

        return $url;
    }

    private function manualFailure(SocialPublication $publication, string $reason): void
    {
        $publication->forceFill(['status' => SocialPublication::STATUS_FAILED, 'phase' => 'failed', 'error' => $reason])->save();
    }

    private function identity(string $token): array
    {
        if (! $this->validToken($token)) {
            throw new RuntimeException('connection_missing');
        }
        $data = $this->read($token, '/me', ['fields' => 'id,name']);
        if ((string) ($data['id'] ?? '') !== SocialSettings::PAGE_ID) {
            throw new RuntimeException('wrong_page');
        }
        if (! is_string($data['name'] ?? null) || trim($data['name']) === '') {
            throw new RuntimeException('connection_failed');
        }

        return ['id' => SocialSettings::PAGE_ID, 'name' => mb_substr(trim($data['name']), 0, 200)];
    }

    private function read(string $token, string $path, array $query = []): array
    {
        try {
            $response = $this->request($token)->get(self::API.$path, $query);
        } catch (Throwable) {
            throw new RuntimeException('connection_failed');
        }
        if (! $response->successful()) {
            $reason = $this->errorReason($response, 'connection_failed');
            throw new RuntimeException($reason === 'rate_limited' ? 'connection_failed' : $reason);
        }
        $data = $response->json();
        if (! is_array($data) || isset($data['error'])) {
            throw new RuntimeException($this->errorReason($response, 'connection_failed'));
        }

        return $data;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(10)
            ->withOptions(['allow_redirects' => false]);
    }

    private function errorReason(Response $response, string $fallback): string
    {
        return match ((int) $response->json('error.code', 0)) {
            102, 190 => 'token_expired',
            10, 200, 294 => 'permission_missing',
            4, 17, 32, 613 => 'rate_limited',
            default => $response->status() === 429 ? 'rate_limited' : $fallback,
        };
    }

    private function validToken(string $token): bool
    {
        return trim($token) !== '' && strlen($token) <= 16384 && ! preg_match('/[\r\n\x00]/', $token);
    }

    private function validPostId(mixed $id): bool
    {
        return (is_string($id) || is_int($id)) && preg_match('/\A[0-9]+(?:_[0-9]+)?\z/D', (string) $id) && strlen((string) $id) <= 100;
    }

    private function fail(SocialPost $post, string $reason): void
    {
        $post->forceFill(['status' => SocialPost::STATUS_FAILED, 'error' => $reason])->save();
    }

    private function unknown(SocialPost $post, string $reason): void
    {
        $post->forceFill(['status' => SocialPost::STATUS_UNKNOWN, 'error' => $reason])->save();
    }
}
