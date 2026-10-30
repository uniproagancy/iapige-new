<?php

namespace App\Services\Social;

use App\Models\SocialPublication;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaChannelPublisher
{
    public const INSTAGRAM_ID = SocialSettings::INSTAGRAM_ID;

    private const API = 'https://graph.facebook.com/v26.0';

    private ?string $checkedTokenHash = null;

    public function __construct(protected SocialSettings $settings, protected PublicationPreparer $preparer) {}

    public function connectionStatus(): array
    {
        $token = $this->settings->token();
        if ($token === null) {
            $this->checkedTokenHash = null;
            foreach (SocialPublication::CHANNELS as $channel) {
                $this->settings->recordChannelStatus($channel, false, 'connection_missing');
            }
            $this->settings->recordInstagramStatus(false, 'connection_missing');

            return $this->settings->status();
        }
        try {
            $page = $this->read($token, '/me', ['fields' => 'id,name']);
            if ((string) ($page['id'] ?? '') !== SocialSettings::PAGE_ID) {
                throw new DomainException('wrong_page');
            }
            $this->settings->recordChannelStatus('facebook_feed', true, 'connected');
            $this->settings->recordChannelStatus('facebook_story', true, 'connected');
        } catch (DomainException $exception) {
            foreach (SocialPublication::CHANNELS as $channel) {
                $this->settings->recordChannelStatus($channel, false, $exception->getMessage());
            }

            return $this->settings->status();
        }
        try {
            $page = $this->read($token, '/'.SocialSettings::PAGE_ID, ['fields' => 'instagram_business_account{id,username}']);
            $id = (string) ($page['instagram_business_account']['id'] ?? '');
            if ($id === '') {
                throw new DomainException('instagram_not_linked');
            }
            if ($id !== self::INSTAGRAM_ID) {
                throw new DomainException('instagram_changed');
            }
            $account = $this->read($token, '/'.$id, ['fields' => 'id,username']);
            if ((string) ($account['id'] ?? '') !== self::INSTAGRAM_ID) {
                throw new DomainException('instagram_changed');
            }
            $limit = $this->read($token, '/'.$id.'/content_publishing_limit', ['fields' => 'quota_usage,config']);
            $quota = $limit['data'][0] ?? $limit;
            $total = (int) ($quota['config']['quota_total'] ?? $quota['config'][0]['quota_total'] ?? 0);
            if ($total > 0 && (int) ($quota['quota_usage'] ?? 0) >= $total) {
                throw new DomainException('quota_exceeded');
            }
            $storedStatus = $this->settings->status();
            $storyVerified = (bool) ($storedStatus['instagram']['story_verified']
                ?? $storedStatus['channel_status']['instagram_story']['connected'] ?? false);
            $this->settings->recordInstagramStatus(true, 'connected', $id, (string) ($account['username'] ?? ''));
            $this->settings->recordChannelStatus('instagram_feed', true, 'connected');
            // The Facebook Login API does not expose account_type. A prior controlled
            // Story capability check must establish support before enabling this channel.
            $this->settings->recordChannelStatus('instagram_story', $storyVerified,
                $storyVerified ? 'connected' : 'not_checked');
        } catch (DomainException $exception) {
            $reason = $exception->getMessage() === 'permission_missing' ? 'instagram_permission_missing' : $exception->getMessage();
            $this->settings->recordInstagramStatus(false, $reason);
            $this->settings->recordChannelStatus('instagram_feed', false, $reason);
            $this->settings->recordChannelStatus('instagram_story', false, $reason);
        }
        $this->checkedTokenHash = hash('sha256', $token);

        return $this->settings->status();
    }

    public function publish(SocialPublication $publication): bool
    {
        if (! in_array($publication->channel, ['instagram_feed', 'facebook_story', 'instagram_story'], true)
            || $publication->status !== SocialPublication::STATUS_PUBLISHING
            || ! in_array($publication->phase, ['preparing', 'container_ready'], true)) {
            return false;
        }
        $token = $this->settings->token();
        if ($token === null || $this->checkedTokenHash !== hash('sha256', $token)) {
            $this->connectionStatus();
        }
        $status = $this->settings->status()['channel_status'][$publication->channel] ?? [];
        if ($token === null || ! ($status['connected'] ?? false)) {
            $publication->forceFill(['status' => 'blocked', 'phase' => null,
                'error' => $status['reason'] ?? 'connection_missing'])->save();

            return false;
        }
        $account = str_starts_with($publication->channel, 'instagram_') ? self::INSTAGRAM_ID : SocialSettings::PAGE_ID;
        if ($publication->account_id !== null && $publication->account_id !== $account) {
            $publication->forceFill(['status' => 'blocked', 'error' => 'account_changed'])->save();

            return false;
        }
        $publication->forceFill(['account_id' => $account])->save();
        try {
            if ($publication->container_id === null && $publication->media_id === null) {
                $publication = $this->preparer->prepare($publication);
            }
            $this->preparer->assertCurrent($publication);

            return $publication->channel === 'facebook_story'
                ? $this->facebookStory($publication, $token) : $this->instagram($publication, $token);
        } catch (DomainException $exception) {
            $publication->forceFill(['status' => 'skipped', 'error' => in_array($exception->getMessage(),
                ['product_unavailable', 'publication_stale', 'media_unavailable'], true) ? $exception->getMessage() : 'preparation_failed'])->save();

            return false;
        } catch (Throwable) {
            $publication->forceFill(['status' => 'failed', 'error' => 'preparation_failed'])->save();

            return false;
        }
    }

    public function reconcileUnknown(): int
    {
        SocialPublication::query()->whereIn('channel', ['instagram_feed', 'facebook_story', 'instagram_story'])
            ->where('status', 'publishing')->where('claimed_at', '<=', CarbonImmutable::now('UTC')->subMinutes(10))
            ->update(['status' => 'unknown', 'error' => 'stale_claim']);
        $query = SocialPublication::query()->where('status', 'unknown')
            ->whereIn('channel', ['instagram_feed', 'facebook_story', 'instagram_story'])
            ->where(fn ($query) => $query->whereNotNull('container_id')->orWhereNotNull('media_id')->orWhereNotNull('external_id'));
        $cursorKey = 'social.publications.reconcile.cursor';
        $cursor = max(0, (int) Cache::get($cursorKey, 0));
        $publications = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit(25)->get();
        if ($publications->isEmpty() && $cursor > 0) {
            $publications = (clone $query)->orderBy('id')->limit(25)->get();
        }
        if ($publications->isEmpty() || ($token = $this->settings->token()) === null) {
            return 0;
        }
        $count = 0;
        foreach ($publications as $publication) {
            $account = str_starts_with($publication->channel, 'instagram_') ? self::INSTAGRAM_ID : SocialSettings::PAGE_ID;
            if ($publication->account_id !== $account) {
                continue;
            }
            if ($this->verify($publication, $token)) {
                $count++;
            }
        }
        // A bounded cursor gives permanently unverifiable old records their turn
        // without starving newer ones, including when timestamps are identical.
        Cache::forever($cursorKey, (int) $publications->last()->id);

        return $count;
    }

    private function facebookStory(SocialPublication $publication, string $token): bool
    {
        if ($publication->media_id === null) {
            if (! $this->phase($publication, 'preparing', 'uploading')) {
                return false;
            }
            $path = $this->preparer->imagePath($publication);
            $stream = $path === null ? false : @fopen($path, 'rb');
            if ($stream === false) {
                throw new DomainException('media_unavailable');
            }
            try {
                $data = $this->write($publication, $token, '/'.SocialSettings::PAGE_ID.'/photos', ['published' => 'false'], $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if ($data === null || ! $this->validId($data['id'] ?? null)) {
                if ($data !== null) {
                    $this->unknown($publication);
                }

                return false;
            }
            $publication->forceFill(['media_id' => (string) $data['id'], 'phase' => 'photo_ready'])->save();
        }
        $this->preparer->assertCurrent($publication);
        if (! $this->phase($publication, 'photo_ready', 'publishing')) {
            return false;
        }
        $data = $this->write($publication, $token, '/'.SocialSettings::PAGE_ID.'/photo_stories', ['photo_id' => $publication->media_id]);
        if ($data === null) {
            return false;
        }
        $id = $data['post_id'] ?? $data['id'] ?? null;
        if ($this->validId($id)) {
            $publication->forceFill(['external_id' => (string) $id])->save();
        }
        $this->unknown($publication, 'verification_failed');

        return $this->verify($publication, $token);
    }

    private function instagram(SocialPublication $publication, string $token): bool
    {
        if ($publication->container_id === null) {
            if (! $this->phase($publication, 'preparing', 'creating_container')) {
                return false;
            }
            $parameters = ['image_url' => $this->preparer->mediaUrl($publication)];
            if ($publication->channel === 'instagram_story') {
                $parameters['media_type'] = 'STORIES';
            } else {
                $parameters['caption'] = mb_substr((string) $publication->caption, 0, 2200);
            }
            $data = $this->write($publication, $token, '/'.self::INSTAGRAM_ID.'/media', $parameters);
            if ($data === null || ! $this->validId($data['id'] ?? null)) {
                if ($data !== null) {
                    $this->unknown($publication);
                }

                return false;
            }
            $publication->forceFill(['container_id' => (string) $data['id'], 'phase' => 'container_ready'])->save();
        }
        try {
            $container = $this->read($token, '/'.$publication->container_id, ['fields' => 'status_code,status']);
        } catch (DomainException) {
            $publication->forceFill(['status' => 'ready', 'phase' => 'container_ready', 'error' => 'connection_failed'])->save();

            return false;
        }
        if (($container['status_code'] ?? '') === 'PUBLISHED') {
            return $this->published($publication);
        }
        if (in_array($container['status_code'] ?? '', ['ERROR', 'EXPIRED'], true)) {
            $publication->forceFill(['status' => 'failed', 'error' => 'media_unavailable'])->save();

            return false;
        }
        if (($container['status_code'] ?? '') !== 'FINISHED') {
            $publication->forceFill(['status' => 'ready', 'phase' => 'container_ready', 'error' => null])->save();

            return false;
        }
        $this->preparer->assertCurrent($publication);
        if (! $this->phase($publication, 'container_ready', 'publishing')) {
            return false;
        }
        $data = $this->write($publication, $token, '/'.self::INSTAGRAM_ID.'/media_publish', ['creation_id' => $publication->container_id]);
        if ($data === null) {
            return false;
        }
        if ($this->validId($data['id'] ?? null)) {
            $publication->forceFill(['external_id' => (string) $data['id']])->save();
        }
        $this->unknown($publication, 'verification_failed');

        return $this->verify($publication, $token);
    }

    private function verify(SocialPublication $publication, string $token): bool
    {
        try {
            if ($publication->channel === 'facebook_story') {
                $after = null;
                for ($page = 0; $page < 5; $page++) {
                    $query = ['fields' => 'id,post_id,media_id,status,url', 'status' => '["PUBLISHED"]', 'limit' => 100];
                    if ($after !== null) {
                        $query['after'] = $after;
                    }
                    $stories = $this->read($token, '/'.SocialSettings::PAGE_ID.'/stories', $query);
                    foreach ($stories['data'] ?? [] as $story) {
                        $status = $story['status'] ?? null;
                        if (is_string($status) && strtoupper($status) === 'PUBLISHED'
                            && (($publication->external_id !== null && (string) ($story['post_id'] ?? $story['id'] ?? '') === $publication->external_id)
                                || ($publication->media_id !== null && (string) ($story['media_id'] ?? '') === $publication->media_id))) {
                            $id = $story['post_id'] ?? $story['id'] ?? null;
                            $publication->forceFill(['external_id' => $this->validId($id) ? (string) $id : $publication->external_id,
                                'permalink' => $this->safeUrl($story['url'] ?? null, 'facebook.com')])->save();

                            return $this->published($publication);
                        }
                    }
                    $next = $stories['paging']['cursors']['after'] ?? null;
                    if (! is_string($next) || $next === '' || $next === $after || empty($stories['paging']['next'])) {
                        break;
                    }
                    $after = $next;
                }
            } else {
                if ($publication->external_id !== null) {
                    $media = $this->read($token, '/'.$publication->external_id, ['fields' => 'id,owner,media_type,permalink']);
                    if ((string) ($media['id'] ?? '') === $publication->external_id
                        && (string) ($media['owner']['id'] ?? $media['owner'] ?? '') === self::INSTAGRAM_ID) {
                        $publication->forceFill(['permalink' => $this->safeUrl($media['permalink'] ?? null, 'instagram.com')])->save();

                        return $this->published($publication);
                    }
                }
                if ($publication->external_id === null && $publication->container_id !== null) {
                    $container = $this->read($token, '/'.$publication->container_id, ['fields' => 'status_code']);
                    if (($container['status_code'] ?? null) === 'PUBLISHED') {
                        return $this->published($publication);
                    }
                }
            }
        } catch (DomainException) {
            // A read failure never authorizes another write.
        }

        return false;
    }

    private function read(string $token, string $path, array $query): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])->get(self::API.$path, $query);
        } catch (Throwable) {
            throw new DomainException('connection_failed');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || isset($data['error'])) {
            throw new DomainException($this->reason($response));
        }

        return $data;
    }

    private function write(SocialPublication $publication, string $token, string $path, array $parameters, $stream = null): ?array
    {
        try {
            $request = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(45)
                ->withOptions(['allow_redirects' => false]);
            if ($stream !== null) {
                $request->attach('source', $stream, 'iapi-story.png', ['Content-Type' => 'image/png']);
            }
            $response = $request->post(self::API.$path, $parameters);
        } catch (Throwable) {
            $this->unknown($publication);

            return null;
        }
        if ($response->serverError()) {
            $this->unknown($publication);

            return null;
        }
        $data = $response->json();
        if ($response->clientError() || (is_array($data) && isset($data['error']))) {
            $reason = $this->reason($response);
            $publication->forceFill(['status' => 'failed', 'error' => $reason])->save();
            if (in_array($reason, ['permission_missing', 'token_expired'], true)) {
                $this->settings->recordChannelStatus($publication->channel, false, $reason);
            }

            return null;
        }
        if (! $response->successful() || ! is_array($data)) {
            $this->unknown($publication);

            return null;
        }

        return $data;
    }

    private function reason(Response $response): string
    {
        return match ((int) $response->json('error.code', 0)) {
            102, 190 => 'token_expired',
            10, 200, 294 => 'permission_missing',
            4, 17, 32, 613 => 'rate_limited',
            default => $response->status() === 429 ? 'rate_limited' : 'connection_failed',
        };
    }

    private function phase(SocialPublication $publication, string $from, string $to): bool
    {
        $changed = SocialPublication::query()->whereKey($publication->id)->where('status', 'publishing')
            ->where('phase', $from)->update(['phase' => $to]);
        $publication->refresh();

        return $changed === 1;
    }

    private function unknown(SocialPublication $publication, string $reason = 'publication_unknown'): void
    {
        $publication->forceFill(['status' => 'unknown', 'error' => $reason])->save();
    }

    private function published(SocialPublication $publication): bool
    {
        $publication->forceFill(['status' => 'published', 'published_at' => CarbonImmutable::now('UTC'), 'error' => null])->save();

        return true;
    }

    private function validId(mixed $id): bool
    {
        return (is_string($id) || is_int($id)) && strlen((string) $id) <= 100
            && preg_match('/\A[0-9]+(?:_[0-9]+)?\z/D', (string) $id);
    }

    private function safeUrl(mixed $url, string $domain): ?string
    {
        if (! is_string($url) || strlen($url) > 500 || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === $domain || str_ends_with($host, '.'.$domain) ? $url : null;
    }
}
