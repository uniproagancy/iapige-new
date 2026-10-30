<?php

declare(strict_types=1);

namespace SocialTests;

use App\Livewire\Admin\Social\Index;
use App\Models\SocialPublication;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;

final class ManualHistoryUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
    }

    public function test_history_survives_a_new_component_and_groups_channel_results_while_hiding_private_previews(): void
    {
        $product = $this->product();
        $key = hash('sha256', 'real-manual-request');
        $facebook = $this->publication($product->id, $key, ['status' => 'published',
            'snapshot' => ['title' => 'Durable manual product'], 'published_at' => now('UTC'),
            'permalink' => 'https://www.facebook.com/103447072710661_123']);
        $this->publication($product->id, $key, ['channel' => 'instagram_feed', 'status' => 'blocked',
            'snapshot' => null, 'error' => 'permission_missing']);
        $this->publication($product->id, hash('sha256', 'private-request'), ['status' => 'published',
            'snapshot' => ['title' => 'Hidden private preview'], 'error' => 'private_preview_only']);
        $this->publication($product->id, hash('sha256', 'automatic-request'), ['origin' => 'automatic',
            'snapshot' => ['title' => 'Automatic product']]);

        $method = new ReflectionMethod(Index::class, 'manualHistory');
        $firstLoad = $method->invoke(new Index);
        $reloaded = $method->invoke(new Index);

        self::assertSame($firstLoad, $reloaded);
        self::assertCount(1, $reloaded);
        self::assertSame('Durable manual product', $reloaded[0]['title']);
        self::assertSame('published', $reloaded[0]['channels']['facebook_feed']['status']);
        self::assertSame('blocked', $reloaded[0]['channels']['instagram_feed']['status']);
        self::assertSame($facebook->permalink, $reloaded[0]['channels']['facebook_feed']['permalink']);
        self::assertNotNull($reloaded[0]['channels']['facebook_feed']['published_at']);
        self::assertStringNotContainsString('Hidden private preview', json_encode($reloaded));
        self::assertStringNotContainsString('Automatic product', json_encode($reloaded));
        self::assertStringNotContainsString('permission_missing', json_encode($reloaded));
        Http::assertNothingSent();
    }

    public function test_history_limits_complete_requests_to_ten_and_rejects_sensitive_links(): void
    {
        $product = $this->product();
        for ($number = 1; $number <= 11; $number++) {
            $key = hash('sha256', 'manual-'.$number);
            $this->publication($product->id, $key, ['snapshot' => ['title' => 'Request '.$number], 'status' => 'published',
                'permalink' => 'https://www.instagram.com/p/test/?access_token=synthetic-secret']);
            $this->publication($product->id, $key, ['channel' => 'instagram_feed', 'snapshot' => ['title' => 'Request '.$number]]);
        }
        $this->publication($product->id, hash('sha256', 'newest-preview'), ['error' => 'private_preview_only']);

        $history = (new ReflectionMethod(Index::class, 'manualHistory'))->invoke(new Index);

        self::assertCount(10, $history);
        self::assertSame('Request 11', $history[0]['title']);
        self::assertSame('Request 2', $history[9]['title']);
        self::assertCount(4, $history[0]['channels']);
        self::assertNull($history[0]['channels']['facebook_feed']['permalink']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($history));
        Http::assertNothingSent();
    }

    protected function publication(int $productId, string $requestKey, array $attributes = []): SocialPublication
    {
        return SocialPublication::create(array_replace([
            'origin' => 'manual', 'product_id' => $productId, 'owner_id' => 42,
            'request_key' => $requestKey, 'channel' => 'facebook_feed', 'status' => 'draft',
            'scheduled_for' => now('UTC'), 'snapshot' => ['title' => 'Product'],
        ], $attributes));
    }
}
