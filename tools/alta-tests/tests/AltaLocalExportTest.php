<?php

declare(strict_types=1);

namespace AltaTests;

use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Support\Facades\Http;

final class AltaLocalExportTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = dirname(__DIR__).'/storage/local-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    protected function client(): AltaClient
    {
        return new AltaClient(['retail_directory' => $this->directory, 'retail_max_age' => 3600]);
    }

    protected function document(array $changes = []): array
    {
        return array_replace_recursive($this->details(), ['collected_at' => gmdate(DATE_ATOM),
            'source_url' => 'https://alta.ge/laptops/synthetic-p741'], $changes);
    }

    protected function write(array $document): string
    {
        $file = $this->directory.'/001234.json';
        file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
        clearstatcache(true, $file);

        return $file;
    }

    public function test_fresh_exact_local_document_and_legacy_details_are_available_without_network(): void
    {
        $this->write($this->document());
        $client = $this->client();
        self::assertSame(799.0, (float) $client->product('001234')['product']['price']);
        self::assertSame(799.0, $client->suggest('001234')['price']);
        self::assertSame('001234', $client->details(741)['product']['barCode']);
        Http::assertNothingSent();
    }

    public function test_missing_local_document_does_not_fall_back_to_network(): void
    {
        self::assertNull($this->client()->product('missing'));
        Http::assertNothingSent();
    }

    public function test_stale_collection_wrong_barcode_and_foreign_source_are_rejected(): void
    {
        foreach ([['collected_at' => '2000-01-01T00:00:00Z'], ['product' => ['barCode' => 'other']],
            ['source_url' => 'https://other.invalid/synthetic-p741'], ['product' => ['price' => 0]]] as $changes) {
            $this->write($this->document($changes));
            try {
                $this->client()->product('001234');
                self::fail('An invalid local export must not become a trusted retail source.');
            } catch (AltaUnavailable) {
                Http::assertNothingSent();
                self::assertTrue(true);
            }
        }
    }

    public function test_old_file_modification_time_is_rejected_even_with_fresh_collection_time(): void
    {
        $file = $this->write($this->document());
        touch($file, time() - 7200);
        clearstatcache(true, $file);
        $this->expectException(AltaUnavailable::class);
        $this->client()->product('001234');
    }
}
