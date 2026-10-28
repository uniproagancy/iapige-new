<?php

declare(strict_types=1);

namespace AltaTests;

use App\Http\Controllers\JsonImportFileController;
use App\Models\User;
use App\Services\Import\JsonImportSource;
use App\Services\Import\JsonImportSourceException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class JsonImportFileControllerTest extends TestCase
{
    private const ID = 'b44ff9c2-4b81-40ef-9f2f-c6a5d9205905';

    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStorage = sys_get_temp_dir().'/iapi-controller-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryStorage, 0700);
        $this->app->useStoragePath($this->temporaryStorage);
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->temporaryStorage);
        $parent = realpath(sys_get_temp_dir());
        if ($resolved && dirname($resolved) === $parent && str_starts_with(basename($resolved), 'iapi-controller-fixture-')) {
            File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    protected function request(string $body = '', string $role = 'admin', ?string $offset = '0'): Request
    {
        $request = Request::create('/admin/import/json-file/'.self::ID.'/chunk', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => strlen($body)], $body);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 42, 'role' => $role]));
        if ($offset !== null) {
            $request->headers->set('X-Upload-Offset', $offset);
        }

        return $request;
    }

    public function test_unauthorized_user_cannot_read_source_or_start_temporary_chunk(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $sources->shouldNotReceive('info');
        try {
            (new JsonImportFileController)->chunk($this->request('[]', 'customer'), self::ID, $sources);
            self::fail('A customer cannot upload admin data.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function test_malformed_offset_is_rejected_before_touching_source(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $sources->shouldNotReceive('info');
        foreach ([null, '-1', '01', '1e3', '0.5', '99999999999999999999999'] as $offset) {
            try {
                (new JsonImportFileController)->chunk($this->request('[]', 'admin', $offset), self::ID, $sources);
                self::fail('Invalid offset was accepted.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('offset', $e->errors());
            }
        }
    }

    public function test_oversized_request_is_rejected_before_creating_temporary_file(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $sources->shouldNotReceive('info');
        $request = $this->request('[]');
        $request->server->set('CONTENT_LENGTH', JsonImportSource::CHUNK_BYTES + 1);
        $response = (new JsonImportFileController)->chunk($request, self::ID, $sources);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('chunk_too_large', $response->getData(true)['error']);
    }

    public function test_owner_mismatch_does_not_append_or_expose_private_paths(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $sources->shouldReceive('info')->once()->with(42, self::ID)
            ->andThrow(new JsonImportSourceException('json_source_forbidden', 403));
        $sources->shouldNotReceive('append');
        $response = (new JsonImportFileController)->chunk($this->request('[]'), self::ID, $sources);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'json_source_forbidden'], $response->getData(true));
    }

    public function test_binary_chunk_is_streamed_exactly_and_temporary_file_is_removed(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $body = '["ქართული","\\n","\\u0000"]';
        $temporaryPath = null;
        $sources->shouldReceive('info')->once()->with(42, self::ID)->andReturn(['size' => strlen($body)]);
        $sources->shouldReceive('append')->once()->withArgs(function ($user, $id, $offset, $path) use ($body, &$temporaryPath) {
            $temporaryPath = $path;

            return $user === 42 && $id === self::ID && $offset === 0 && is_file($path)
                && file_get_contents($path) === $body;
        })->andReturn(['received' => strlen($body), 'size' => strlen($body)]);
        $response = (new JsonImportFileController)->chunk($this->request($body), self::ID, $sources);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['received' => strlen($body), 'total' => strlen($body)], $response->getData(true));
        self::assertNotNull($temporaryPath);
        self::assertFileDoesNotExist($temporaryPath);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_failed_append_also_removes_request_file(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $temporaryPath = null;
        $sources->shouldReceive('info')->once()->andReturn(['size' => 2]);
        $sources->shouldReceive('append')->once()->withArgs(function ($user, $id, $offset, $path) use (&$temporaryPath) {
            $temporaryPath = $path;

            return true;
        })->andThrow(new JsonImportSourceException('json_chunk_offset', 409));
        $response = (new JsonImportFileController)->chunk($this->request('[]'), self::ID, $sources);
        self::assertSame(409, $response->getStatusCode());
        self::assertFileDoesNotExist($temporaryPath);
    }

    public function test_finish_returns_only_safe_metadata_bound_to_current_user(): void
    {
        $sources = Mockery::mock(JsonImportSource::class);
        $sources->shouldReceive('finalize')->once()->with(42, self::ID)->andReturn([
            'id' => self::ID, 'name' => 'products.json', 'size' => 2, 'sha256' => hash('sha256', '[]'),
            'supplier' => 'alta', 'state' => 'ready', 'path' => '/private/secret', 'user_id' => 42,
        ]);
        $response = (new JsonImportFileController)->finish($this->request(), self::ID, $sources);
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('path', $response->getData(true));
        self::assertArrayNotHasKey('user_id', $response->getData(true));
        self::assertSame(hash('sha256', '[]'), $response->getData(true)['sha256']);
    }
}
