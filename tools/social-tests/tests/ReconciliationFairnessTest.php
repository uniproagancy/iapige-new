<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPublication;
use App\Services\Social\MetaChannelPublisher;
use App\Services\Social\PublicationPreparer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;

class ReconciliationFairnessTest extends TestCase
{
    public function test_twenty_five_expired_stories_cannot_starve_a_newer_verifiable_publication(): void
    {
        (require SOCIAL_MULTICHANNEL_PAYLOAD.'/database/migrations/2026_10_29_120000_create_social_publications.php')->up();
        config(['social.page_access_token' => 'synthetic-fairness-page-token']);
        $product = $this->product();
        foreach (range(1, 26) as $number) {
            $publication = SocialPublication::create([
                'origin' => 'manual', 'owner_id' => 1, 'product_id' => $product->id,
                'request_key' => hash('sha256', 'fairness-'.$number), 'channel' => 'instagram_story',
                'account_id' => MetaChannelPublisher::INSTAGRAM_ID, 'scheduled_for' => now('UTC'),
                'status' => 'unknown', 'phase' => 'publishing', 'container_id' => (string) (1000 + $number),
            ]);
        }
        $last = $publication;
        Http::fake(fn (Request $request) => Http::response([
            'status_code' => parse_url($request->url(), PHP_URL_PATH) === '/v26.0/1026' ? 'PUBLISHED' : 'EXPIRED',
        ]));
        $publisher = app()->make(MetaChannelPublisher::class, ['preparer' => Mockery::mock(PublicationPreparer::class)]);

        $this->assertSame(0, $publisher->reconcileUnknown());
        $this->assertSame('unknown', $last->fresh()->status);
        $this->assertSame(1, $publisher->reconcileUnknown());
        $this->assertSame('published', $last->fresh()->status);
        $this->assertSame(25, SocialPublication::where('status', 'unknown')->count());
        $this->assertSame(0, $publisher->reconcileUnknown());
        $this->assertSame(25, SocialPublication::where('status', 'unknown')->count());
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }
}
