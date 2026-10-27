<?php

declare(strict_types=1);

namespace AltaTests;

use App\Http\Controllers\AltaBrowserController;
use App\Http\Controllers\AltaStockFeedController;
use App\Http\Middleware\TrackPageView;
use App\Livewire\Admin\Suppliers\Index as SupplierIndex;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Facebook\Pixel;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaStockFeed;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AltaStockFeedTest extends TestCase
{
    protected Supplier $supplier;

    protected AltaStockFeed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27T12:00:00Z'));
        config(['app.url' => 'https://iapi.invalid', 'app.key' => 'base64:'.base64_encode(str_repeat('fixture-key-', 3))]);
        $this->app['router']->get('/feeds/alta-stock.json', AltaStockFeedController::class)->name('feeds.alta-stock');
        $this->app['router']->getRoutes()->refreshNameLookups();
        URL::forceRootUrl('https://iapi.invalid');
        $this->supplier = Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 20, 'config' => ['locale' => 'ka', 'timeout' => 25, 'private_token' => 'fixture-private-token'], 'markup' => []]);
        $this->feed = new AltaStockFeed;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function row(string $item = '001234', int $quantity = 9, mixed $synced = 'now', ?int $supplierId = null): void
    {
        DB::table('supplier_stocks')->insert(['supplier_id' => $supplierId ?? $this->supplier->id, 'external_id' => $item,
            'quantity' => $quantity, 'cost_price' => 123.45, 'data' => json_encode(['price' => 123.45, 'secret' => 'fixture-dealer-secret']),
            'synced_at' => $synced === 'now' ? now() : $synced]);
    }

    protected function response(string $url, ?string $role = null)
    {
        $request = Request::create($url, 'GET');
        $user = $role === null ? null : (new User)->forceFill(['id' => 73, 'role' => $role]);
        $request->setUserResolver(fn () => $user);

        return (new AltaStockFeedController)($request, $this->feed);
    }

    public function test_snapshot_is_one_whitelisted_database_read_with_leading_zeroes_zero_quantity_and_oldest_time(): void
    {
        $oldest = now()->subHours(25);
        $this->row('001235', 0, $oldest);
        $this->row('001234', 9);
        $this->row('bad/code', 7);
        $this->row(str_repeat('a', 65), 7);
        $other = Supplier::create(['code' => 'other', 'name' => 'Other fixture', 'driver' => AltaDriver::class, 'is_active' => true]);
        $this->row('999999', 99, supplierId: $other->id);
        DB::enableQueryLog();
        $data = $this->feed->snapshot($this->supplier);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame('iapi.supplier-stock.v1', $data['schema']);
        self::assertSame('alta', $data['supplier']);
        self::assertSame('supplier_stocks', $data['source']);
        self::assertSame([['item' => '001234', 'quantity' => 9], ['item' => '001235', 'quantity' => 0]], $data['items']);
        self::assertSame(2, $data['count']);
        self::assertSame($oldest->getTimestamp(), Carbon::parse($data['synced_at'])->getTimestamp());
        self::assertCount(1, $queries);
        self::assertStringNotContainsString('cost_price', $queries[0]['query']);
        self::assertStringNotContainsString('"data"', $queries[0]['query']);
        self::assertStringNotContainsString('fixture-', json_encode($data));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_link_requires_explicit_rotation_and_rotation_preserves_fresh_config(): void
    {
        self::assertNull($this->feed->link($this->supplier));
        $this->supplier->update(['config' => ['locale' => 'ka', 'timeout' => 25]]);
        $staleModel = $this->supplier->fresh();
        Supplier::whereKey($this->supplier->id)->update(['config' => json_encode(['locale' => 'ka', 'timeout' => 91, 'added_later' => 'retained'])]);
        $link = $this->feed->rotateLink($staleModel);
        self::assertStringStartsWith('https://iapi.invalid/feeds/alta-stock.json?', $link);
        self::assertTrue(Request::create($link)->hasValidSignature(false));
        self::assertSame(91, $this->supplier->fresh()->config['timeout']);
        self::assertSame('retained', $this->supplier->fresh()->config['added_later']);
        self::assertSame($link, $this->feed->link($staleModel));
        self::assertStringNotContainsString('retained', $link);
        self::assertStringNotContainsString('private', $link);
    }

    #[DataProvider('roles')]
    public function test_valid_read_capability_is_independent_of_admin_role_and_never_writes(?string $role): void
    {
        $this->row();
        $link = $this->feed->rotateLink($this->supplier);
        $before = DB::table('supplier_stocks')->get()->toArray();
        DB::enableQueryLog();
        $response = $this->response($link, $role);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['item' => '001234', 'quantity' => 9]], $response->getData(true)['items']);
        self::assertEquals($before, DB::table('supplier_stocks')->get()->toArray());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame([], array_filter($queries, fn ($query) => ! str_starts_with(strtolower($query['query']), 'select')));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function roles(): array
    {
        return ['anonymous' => [null], 'customer' => [User::CUSTOMER], 'operator' => [User::OPERATOR], 'owner' => [User::ADMIN]];
    }

    public function test_unsigned_tampered_and_extra_query_parameters_are_denied_before_stock_is_queried(): void
    {
        $link = $this->feed->rotateLink($this->supplier);
        foreach (['https://iapi.invalid/feeds/alta-stock.json', preg_replace('/signature=[^&]+/', 'signature=bad', $link),
            preg_replace('/version=[^&]+/', 'version=bad', $link), $link.'&extra=1'] as $url) {
            DB::enableQueryLog();
            $response = $this->response($url, User::ADMIN);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertSame(403, $response->getStatusCode());
            self::assertArrayNotHasKey('items', $response->getData(true));
            self::assertSame([], array_filter($queries, fn ($query) => str_contains($query['query'], 'supplier_stocks')));
        }
    }

    public function test_rotation_revokes_the_old_link_and_relative_signatures_survive_proxy_host_changes(): void
    {
        $this->row();
        $first = $this->feed->rotateLink($this->supplier);
        $second = $this->feed->rotateLink($this->supplier);
        self::assertNotSame($first, $second);
        self::assertSame(403, $this->response($first)->getStatusCode());
        self::assertSame(200, $this->response($second)->getStatusCode());
        self::assertSame(200, $this->response(str_replace('https://iapi.invalid', 'http://internal-proxy.invalid', $second))->getStatusCode());
    }

    public function test_missing_or_stale_snapshot_returns_json_503_without_items(): void
    {
        $link = $this->feed->rotateLink($this->supplier);
        self::assertSame(503, $this->response($link)->getStatusCode());
        self::assertSame('stock_snapshot_unavailable', $this->response($link)->getData(true)['error']);
        $this->row('001234', 9, now()->subHours(26)->subSecond());
        $this->row('001235', 0);
        $response = $this->response($link);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('stock_snapshot_stale', $response->getData(true)['error']);
        self::assertArrayNotHasKey('items', $response->getData(true));
    }

    public function test_missing_sync_time_does_not_look_like_a_fresh_snapshot(): void
    {
        $this->row(synced: null);
        $response = $this->response($this->feed->rotateLink($this->supplier));
        self::assertSame(503, $response->getStatusCode());
        self::assertArrayNotHasKey('items', $response->getData(true));
    }

    public function test_future_sync_time_is_rejected_even_when_another_row_is_fresh(): void
    {
        $this->row('001234', 9);
        $this->row('001235', 0, now()->addMinutes(5));
        $link = $this->feed->rotateLink($this->supplier);
        self::assertSame(200, $this->response($link)->getStatusCode());
        DB::table('supplier_stocks')->where('external_id', '001235')->update(['synced_at' => now()->addMinutes(5)->addSecond()]);
        $response = $this->response($link);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('stock_snapshot_unavailable', $response->getData(true)['error']);
        self::assertArrayNotHasKey('items', $response->getData(true));
    }

    public function test_inactive_or_wrong_driver_is_rejected_after_capability_check(): void
    {
        $link = $this->feed->rotateLink($this->supplier);
        $this->supplier->update(['is_active' => false]);
        self::assertSame(403, $this->response($link.'&tampered=1')->getStatusCode());
        self::assertSame(503, $this->response($link)->getStatusCode());
        $this->supplier->update(['is_active' => true, 'driver' => 'OtherDriver']);
        self::assertSame(503, $this->response($link)->getStatusCode());
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_legacy_owner_endpoint_stays_owner_only(?string $role): void
    {
        $request = Request::create('https://iapi.invalid/admin/alta-browser/feed', 'GET');
        $user = $role === null ? null : (new User)->forceFill(['role' => $role]);
        $request->setUserResolver(fn () => $user);
        try {
            (new AltaBrowserController)->feed($request);
            self::fail('The legacy admin feed remains owner-only.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public static function nonOwnerRoles(): array
    {
        return ['anonymous' => [null], 'customer' => [User::CUSTOMER], 'operator' => [User::OPERATOR]];
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_owner_ui_cannot_rotate_feed_links_for_other_roles(?string $role): void
    {
        $user = $role === null ? null : (new User)->forceFill(['role' => $role]);
        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('user')->andReturn($user);
        $this->app->instance(AuthFactory::class, $auth);
        $feed = Mockery::mock(AltaStockFeed::class);
        $feed->shouldNotReceive('rotateLink');

        try {
            (new SupplierIndex)->createAltaStockLink($feed);
            self::fail('Only the owner may create or replace a shared feed capability.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(403, $e->getStatusCode());
        }

        self::assertArrayNotHasKey('stock_feed_version', $this->supplier->fresh()->config);
    }

    public function test_signed_feed_page_never_emits_a_pixel_event(): void
    {
        $pixel = Mockery::mock(Pixel::class);
        $pixel->shouldNotReceive('capture');
        $pixel->shouldNotReceive('dispatchEvent');
        $request = Request::create('https://iapi.invalid/feeds/alta-stock.json?version=fixture&signature=fixture',
            'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 Fixture']);
        $response = (new TrackPageView($pixel))->handle($request, fn () => new Response('feed'));
        self::assertSame('feed', $response->getContent());
    }
}
