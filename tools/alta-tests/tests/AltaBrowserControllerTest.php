<?php

declare(strict_types=1);

namespace AltaTests;

use App\Http\Controllers\AltaBrowserController;
use App\Jobs\ImportProductJob;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AltaBrowserControllerTest extends TestCase
{
    protected Supplier $supplier;

    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = dirname(__DIR__).'/storage/browser-'.bin2hex(random_bytes(6));
        config(['services.alta.retail_directory' => $this->directory]);
        $this->supplier = Supplier::create(['code' => 'alta', 'name' => 'Alta fixture', 'driver' => AltaDriver::class,
            'is_active' => true, 'priority' => 20, 'config' => [], 'markup' => []]);
    }

    protected function tearDown(): void
    {
        $this->removeFixtureDirectory($this->directory);
        parent::tearDown();
    }

    protected function document(array $changes = []): array
    {
        return array_replace_recursive($this->details(), [
            'schema' => 'iapi.alta.visible-product.v1', 'collected_at' => gmdate(DATE_ATOM),
            'source_url' => 'https://alta.ge/laptops/synthetic-p741',
            'product' => ['imageUrl' => 'https://imgstore.alta.ge/images/synthetic-primary.webp',
                'images' => ['https://imgstore.alta.ge/images/synthetic-primary.webp', 'https://imgstore.alta.ge/images/synthetic-side.webp']],
        ], $changes);
    }

    protected function request(array $input, ?string $role = User::ADMIN, string $method = 'POST'): Request
    {
        if ($method === 'POST' && isset($input['document']['product']) && ! array_key_exists('assets', $input)) {
            $product = $input['document']['product'];
            $urls = array_slice(array_values(array_unique(array_merge([$product['imageUrl']], $product['images']))), 0, 8);
            $input['assets'] = array_map(fn ($url) => ['url' => $url, 'base64' => $this->pngBase64()], $urls);
        }
        $request = $method === 'GET'
            ? Request::create('https://iapi.invalid/admin/alta-browser/status', 'GET', $input)
            : Request::create('https://iapi.invalid/admin/alta-browser/product', 'POST', [], [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($input, JSON_THROW_ON_ERROR));
        $user = $role === null ? null : (new User)->forceFill(['id' => 1, 'name' => 'Synthetic fixture', 'role' => $role]);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return $request;
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_document_is_rejected_before_private_write_or_queue(array $changes): void
    {
        try {
            (new AltaBrowserController)->store($this->request(['expected_item' => '001234', 'document' => $this->document($changes)]));
            self::fail('Invalid browser exports must not be queued or written.');
        } catch (ValidationException) {
            self::assertDirectoryDoesNotExist($this->directory);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public static function invalidDocuments(): array
    {
        return [
            'wrong barcode' => [['product' => ['barCode' => '001235']]],
            'stale collection' => [['collected_at' => '2000-01-01T00:00:00Z']],
            'foreign source' => [['source_url' => 'https://foreign.invalid/laptops/synthetic-p741']],
            'wrong source product id' => [['source_url' => 'https://alta.ge/laptops/synthetic-p742']],
            'foreign primary image' => [['product' => ['imageUrl' => 'https://foreign.invalid/image.webp']]],
            'foreign secondary image' => [['product' => ['images' => ['https://imgstore.alta.ge/main.webp', 'https://foreign.invalid/second.webp']]]],
            'unsafe image scheme' => [['product' => ['imageUrl' => 'http://imgstore.alta.ge/image.webp']]],
        ];
    }

    public function test_guest_customer_and_operator_are_rejected_before_private_write_or_queue(): void
    {
        foreach ([null, User::CUSTOMER, User::OPERATOR] as $role) {
            try {
                (new AltaBrowserController)->store($this->request(['expected_item' => '001234', 'document' => $this->document()], $role));
                self::fail('Only an authenticated owner can write browser exports.');
            } catch (HttpExceptionInterface $e) {
                self::assertSame(403, $e->getStatusCode());
                self::assertDirectoryDoesNotExist($this->directory);
                Queue::assertNothingPushed();
            }
        }
    }

    public function test_missing_foreign_nonimage_and_invalid_base64_assets_are_rejected_before_any_private_write(): void
    {
        $urls = ['https://imgstore.alta.ge/images/synthetic-primary.webp', 'https://imgstore.alta.ge/images/synthetic-side.webp'];
        $valid = array_map(fn ($url) => ['url' => $url, 'base64' => $this->pngBase64()], $urls);
        foreach ([[], [$valid[0]], [array_replace($valid[0], ['url' => 'https://foreign.invalid/image.webp']), $valid[1]],
            [array_replace($valid[0], ['base64' => base64_encode('<html>This is not an image</html>')]), $valid[1]],
            [array_replace($valid[0], ['base64' => 'not-base64!']), $valid[1]]] as $assets) {
            try {
                (new AltaBrowserController)->store($this->request(['expected_item' => '001234', 'document' => $this->document(), 'assets' => $assets]));
                self::fail('Every required asset must contain valid image bytes for the exact product URL.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('assets', $e->errors());
                self::assertDirectoryDoesNotExist($this->directory);
                Queue::assertNothingPushed();
                Http::assertNothingSent();
            }
        }
    }

    public function test_accepted_export_only_writes_private_source_and_queues_without_trusting_browser_stock(): void
    {
        $response = (new AltaBrowserController)->store($this->request(['expected_item' => '001234',
            'document' => $this->document(['product' => ['storageQuantity' => 9999,
                'description' => '<p>Useful description.</p><script>evil()</script>'],
                'availabilityInStores' => [['inStock' => true]]])]));
        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($response->getData(true)['queued']);
        $saved = json_decode(file_get_contents($this->directory.'/001234.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $saved['product']['storageQuantity']);
        self::assertSame([], $saved['availabilityInStores']);
        self::assertStringNotContainsString('<script', $saved['product']['description']);
        self::assertSame(799, $saved['product']['price']);
        self::assertSame(999, $saved['product']['previousPrice']);
        self::assertCount(2, glob($this->directory.'/assets/*.image'));
        self::assertSame(0, DB::table('products')->count());
        self::assertSame(0, DB::table('product_offers')->count());
        Queue::assertPushedOn('import:alta', ImportProductJob::class, fn ($job) => $job->supplierId === $this->supplier->id && $job->externalId === '001234');
        Http::assertNothingSent();
    }

    public function test_price_lock_and_active_promotion_return_conflicts_without_overwriting_operator_data(): void
    {
        $this->existing(['price_lock' => true, 'price' => 899]);
        $before = DB::table('products')->first();
        $response = (new AltaBrowserController)->store($this->request(['expected_item' => '001234', 'document' => $this->document()]));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('price_lock', $response->getData(true)['conflict']);
        self::assertEquals($before, DB::table('products')->first());
        self::assertDirectoryDoesNotExist($this->directory);
        Queue::assertNothingPushed();

        DB::table('products')->update(['price_lock' => false, 'price' => 799]);
        DB::table('promotions')->insert(['id' => 1, 'is_active' => true]);
        DB::table('promotion_product')->insert(['promotion_id' => 1, 'product_id' => 16, 'promo_price' => 699]);
        $response = (new AltaBrowserController)->store($this->request(['expected_item' => '001234', 'document' => $this->document()]));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('promotion', $response->getData(true)['conflict']);
        self::assertSame(699.0, (float) $response->getData(true)['selling_price']);
        self::assertDirectoryDoesNotExist($this->directory);
        Queue::assertNothingPushed();
    }

    public function test_repeated_pending_export_reuses_one_private_file_and_one_queued_job(): void
    {
        $document = $this->document();
        $controller = new AltaBrowserController;
        $first = $controller->store($this->request(['expected_item' => '001234', 'document' => $document]));
        $path = $this->directory.'/001234.json';
        $hash = hash_file('sha256', $path);
        $second = $controller->store($this->request(['expected_item' => '001234', 'document' => $document]));
        self::assertSame(202, $first->getStatusCode());
        self::assertSame(202, $second->getStatusCode());
        self::assertTrue($second->getData(true)['queued']);
        self::assertTrue($second->getData(true)['replayed']);
        self::assertSame($hash, hash_file('sha256', $path), 'A replay must not replace the pending worker source.');
        Queue::assertPushed(ImportProductJob::class, 1);
        self::assertCount(2, glob($this->directory.'/assets/*.image'));
    }

    public function test_status_requires_completed_matching_hash_freshness_images_and_actual_price_parity(): void
    {
        $this->existing();
        mkdir($this->directory, 0700, true);
        $path = $this->directory.'/001234.json';
        file_put_contents($path, json_encode($this->document(), JSON_THROW_ON_ERROR));
        DB::table('product_images')->insert(['product_id' => 16, 'path' => 'products/synthetic.webp']);
        $status = fn () => (new AltaBrowserController)->status($this->request(['item' => '001234'], method: 'GET'))->getData(true);
        self::assertFalse($status()['matches_export'], 'A queued export is not a completed import.');
        $key = 'alta-browser-complete:'.$this->supplier->id.':001234';
        Cache::put($key, ['hash' => hash_file('sha256', $path), 'images_complete' => false]);
        self::assertFalse($status()['matches_export']);
        Cache::put($key, ['hash' => hash_file('sha256', $path), 'images_complete' => true]);
        self::assertTrue($status()['matches_export']);
        DB::table('products')->update(['old_price' => null]);
        self::assertFalse($status()['matches_export']);
        DB::table('products')->update(['old_price' => 999]);
        file_put_contents($path, json_encode($this->document(['product' => ['price' => 899]]), JSON_THROW_ON_ERROR));
        self::assertFalse($status()['processed_export'], 'Completion of an older export does not acknowledge a replacement.');
        self::assertFalse($status()['matches_export']);
        file_put_contents($path, json_encode($this->document(['collected_at' => '2000-01-01T00:00:00Z']), JSON_THROW_ON_ERROR));
        Cache::put($key, ['hash' => hash_file('sha256', $path), 'images_complete' => true]);
        self::assertFalse($status()['retail_fresh']);
        self::assertFalse($status()['matches_export']);
    }

    public function test_feed_exposes_item_name_and_stock_without_dealer_prices(): void
    {
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andReturn([['item' => '001234', 'name' => 'Synthetic item', 'quantity' => 3, 'price' => 599]]);
        $controller = new class($b2b) extends AltaBrowserController {
            public function __construct(private AltaB2bClient $fixture) {}

            protected function b2b(Supplier $supplier): AltaB2bClient
            {
                return $this->fixture;
            }
        };
        $data = $controller->feed($this->request([], method: 'GET'))->getData(true);
        self::assertSame(['item', 'name', 'quantity', 'url'], array_keys($data['items'][0]));
        self::assertSame(3, $data['items'][0]['quantity']);
        self::assertArrayNotHasKey('price', $data['items'][0]);
        Http::assertNothingSent();
    }

    public function test_full_export_includes_zero_stock_only_when_requested_and_keeps_prices_private(): void
    {
        $b2b = Mockery::mock(AltaB2bClient::class);
        $b2b->shouldReceive('all')->andReturn([
            ['item' => '001234', 'name' => 'Available', 'quantity' => 3, 'price' => 599],
            ['item' => '001235', 'name' => 'Last unit', 'quantity' => 1, 'price' => 499],
            ['item' => '001236', 'name' => 'Unavailable', 'quantity' => 0, 'price' => 399],
        ]);
        $controller = new class($b2b) extends AltaBrowserController {
            public function __construct(private AltaB2bClient $fixture) {}

            protected function b2b(Supplier $supplier): AltaB2bClient
            {
                return $this->fixture;
            }
        };
        $default = $controller->feed($this->request([], method: 'GET'))->getData(true);
        self::assertSame(['001234'], array_column($default['items'], 'item'));
        $full = $controller->feed($this->request(['include_out_of_stock' => '1'], method: 'GET'))->getData(true);
        self::assertSame(['001234', '001235', '001236'], array_column($full['items'], 'item'));
        self::assertSame([3, 1, 0], array_column($full['items'], 'quantity'));
        self::assertTrue($full['include_out_of_stock']);
        foreach ($full['items'] as $item) {
            self::assertArrayNotHasKey('price', $item);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    protected function existing(array $changes = []): void
    {
        DB::table('products')->insert(array_replace(['id' => 16, 'sku' => 'alta-001234', 'price' => 799,
            'old_price' => 999, 'cost_price' => 799, 'price_lock' => false, 'stock' => 4, 'status' => 'active'], $changes));
        DB::table('product_offers')->insert(['product_id' => 16, 'supplier_id' => $this->supplier->id,
            'external_id' => '001234', 'cost_price' => 799, 'old_cost_price' => 999, 'stock' => 4]);
    }
}
