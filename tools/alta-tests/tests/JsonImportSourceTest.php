<?php

declare(strict_types=1);

namespace AltaTests;

use App\Services\Import\JsonImportSource;
use App\Services\Import\JsonImportSourceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class JsonImportSourceTest extends TestCase
{
    protected string $directory;

    protected JsonImportSource $source;

    protected array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
        $this->directory = dirname(__DIR__).'/storage/json-source-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->source = new JsonImportSource($this->directory.'/private');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (str_starts_with(basename($path), 'iapi-json-test-') && dirname($path) === sys_get_temp_dir()) {
                @unlink($path);
            }
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                chmod($file->getPathname(), 0600);
            }
        }

        $this->removeFixtureDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function temporary(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iapi-json-test-');
        $this->temporary[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }

    protected function stage(string $json): array
    {
        return $this->stageFile($this->temporary($json));
    }

    protected function stageFile(string $path): array
    {
        $info = $this->source->create(73, 'alta', 'სრული-export.json', filesize($path));
        $handle = fopen($path, 'rb');
        $offset = 0;

        try {
            while (! feof($handle)) {
                $bytes = fread($handle, JsonImportSource::CHUNK_BYTES);

                if ($bytes === '') {
                    break;
                }

                $info = $this->source->append(73, $info['id'], $offset, $this->temporary($bytes));
                $offset = $info['received'];
            }
        } finally {
            fclose($handle);
        }

        return $this->source->finalize(73, $info['id']);
    }

    protected function jobPath(array $info, string $name): string
    {
        return $this->directory.'/private/73/'.$info['id'].'/'.$name;
    }

    protected function reason(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected safe source error '.$reason);
        } catch (JsonImportSourceException $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($reason, $error->getMessage());
            self::assertStringNotContainsString($this->directory, $error->getMessage());
        }
    }

    public function test_single_object_and_array_with_bom_unicode_escaped_quotes_and_braces_round_trip(): void
    {
        $product = ['product' => ['id' => 741, 'name' => 'ქართული { } [ ], "quote" \\ slash', 'description' => "line\nnext"]];

        foreach ([$product, [$product, ['product' => ['id' => 742]]]] as $value) {
            $json = "\xEF\xBB\xBF \n".json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $info = $this->stage($json);
            self::assertSame(hash('sha256', $json), $info['sha256']);
            self::assertSame(strlen($json), $info['size']);
            self::assertArrayNotHasKey('path', $info);
            $rows = [];
            $cursor = 0;

            do {
                $batch = $this->source->readBatch(73, $info['id'], $cursor, 1, 'alta');
                $cursor = $batch['next_cursor'];

                foreach ($batch['rows'] as $row) {
                    self::assertSame(hash('sha256', $row['json']), $row['sha256']);
                    $rows[] = json_decode($row['json'], true, flags: JSON_THROW_ON_ERROR);
                }
            } while (! $batch['eof']);

            self::assertSame(array_is_list($value) ? $value : [$value], $rows);
            self::assertSame(strlen($json), $cursor);
            self::assertSame([], $this->source->readBatch(73, $info['id'], $cursor)['rows']);
        }

        self::assertSame(0, DB::table('products')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_file_over_ten_megabytes_and_one_hundred_products_is_read_in_small_batches(): void
    {
        $path = $this->temporary('');
        $handle = fopen($path, 'wb');
        fwrite($handle, '[');

        for ($i = 0; $i < 125; $i++) {
            fwrite($handle, ($i ? ',' : '').json_encode(['product' => ['id' => $i + 1, 'name' => 'Synthetic'], 'padding' => str_repeat('x', 100000)]));
        }

        fwrite($handle, ']');
        fclose($handle);
        self::assertGreaterThan(10 * 1024 * 1024, filesize($path));
        $info = $this->stageFile($path);
        $cursor = 0;
        $count = 0;
        $before = memory_get_usage(true);

        do {
            $batch = $this->source->readBatch(73, $info['id'], $cursor, 3);
            self::assertLessThanOrEqual(3, count($batch['rows']));
            $count += count($batch['rows']);
            $cursor = $batch['next_cursor'];
        } while (! $batch['eof']);

        self::assertSame(125, $count);
        self::assertLessThan(20 * 1024 * 1024, memory_get_usage(true) - $before);
        self::assertSame(0, DB::table('products')->count());
        self::assertSame(0, DB::table('product_offers')->count());
    }

    public function test_utf8_and_escaped_characters_can_span_verified_chunk_boundaries(): void
    {
        $prefix = '{"product":{"id":741,"name":"';
        $json = $prefix.str_repeat('x', JsonImportSource::CHUNK_BYTES - strlen($prefix) - 1).'ქართული \\"quoted } {"}}';
        $info = $this->stage($json);
        $batch = $this->source->readBatch(73, $info['id']);
        self::assertTrue($batch['eof']);
        self::assertSame($json, $batch['rows'][0]['json']);
    }

    public function test_batch_byte_budget_returns_fewer_records_instead_of_accumulating_large_rows(): void
    {
        $path = $this->temporary('');
        $handle = fopen($path, 'wb');
        fwrite($handle, '[');
        $padding = str_repeat('x', 17 * 1024 * 1024);

        for ($i = 0; $i < 2; $i++) {
            fwrite($handle, ($i ? ',' : '').json_encode(['product' => ['id' => $i + 1], 'padding' => $padding]));
        }

        unset($padding);
        fwrite($handle, ']');
        fclose($handle);
        $info = $this->stageFile($path);
        $first = $this->source->readBatch(73, $info['id'], 0, 10);
        self::assertCount(1, $first['rows']);
        self::assertFalse($first['eof']);
        self::assertLessThan(JsonImportSource::MAX_BATCH_BYTES, strlen($first['rows'][0]['json']));
        $second = $this->source->readBatch(73, $info['id'], $first['next_cursor'], 10);
        self::assertCount(1, $second['rows']);
        self::assertTrue($second['eof']);
        self::assertSame(2, json_decode($second['rows'][0]['json'], true)['product']['id']);
    }

    #[DataProvider('invalidJson')]
    public function test_invalid_or_truncated_sources_never_reach_complete_preview(string $json): void
    {
        $info = $this->stage($json);
        $this->reason('json_source_invalid_json', fn () => $this->source->readBatch(73, $info['id']));
        self::assertSame(0, DB::table('products')->count());
    }

    public static function invalidJson(): array
    {
        return ['trailing comma' => ['[{"id":1},]'], 'missing closing array' => ['[{"id":1}'],
            'truncated string' => ['[{"name":"unfinished'], 'missing comma' => ['[{"id":1}{"id":2}]'],
            'array garbage' => ['[] garbage'], 'object garbage' => ['{"id":1} garbage'],
            'number item' => ['[1]'], 'string item' => ['["text"]'], 'nested array item' => ['[[{}]]'],
            'invalid literal' => ['[{"id":Infinity}]'], 'invalid UTF8' => ["[{\"name\":\"\xff\"}]"]];
    }

    public function test_bad_later_records_fail_before_full_preview_finishes(): void
    {
        $info = $this->stage('[{"id":1},{"id":2},{"id":3},]');
        $first = $this->source->readBatch(73, $info['id'], 0, 1);
        self::assertFalse($first['eof']);
        self::assertCount(1, $first['rows']);
        $this->reason('json_source_invalid_json', fn () => $this->source->readBatch(73, $info['id'], $first['next_cursor']));
    }

    public function test_chunk_retries_are_idempotent_and_mismatches_or_gaps_are_rejected(): void
    {
        $bytes = '['.str_repeat(' ', JsonImportSource::CHUNK_BYTES - 1);
        $info = $this->source->create(73, 'alta', 'all.json', JsonImportSource::CHUNK_BYTES + 2);
        $temporary = $this->temporary($bytes);
        $first = $this->source->append(73, $info['id'], 0, $temporary);
        self::assertSame(JsonImportSource::CHUNK_BYTES, $first['received']);
        self::assertSame($first, $this->source->append(73, $info['id'], 0, $temporary));
        $this->reason('json_chunk_mismatch', fn () => $this->source->append(73, $info['id'], 0, $this->temporary(str_repeat('x', strlen($bytes)))));
        $this->reason('json_chunk_invalid', fn () => $this->source->append(73, $info['id'], 1, $temporary));
        $this->reason('json_source_incomplete', fn () => $this->source->finalize(73, $info['id']));
        $this->source->append(73, $info['id'], JsonImportSource::CHUNK_BYTES, $this->temporary('] '));
        self::assertTrue($this->source->readBatch(73, $this->source->finalize(73, $info['id'])['id'])['eof']);
    }

    public function test_user_and_supplier_binding_and_path_validation_prevent_cross_source_access(): void
    {
        $info = $this->stage('[{"product":{"id":741}}]');
        $this->reason('json_source_not_found', fn () => $this->source->info(74, $info['id']));
        $this->reason('json_source_changed', fn () => $this->source->readBatch(73, $info['id'], supplierCode: 'other'));
        $this->reason('json_source_invalid', fn () => $this->source->info(73, '../meta.json'));
        $this->reason('json_cursor_invalid', fn () => $this->source->readBatch(73, $info['id'], -1));
        $this->reason('json_cursor_invalid', fn () => $this->source->readBatch(73, $info['id'], $info['size'] + 1));
        $this->reason('json_source_state', fn () => $this->source->append(73, $info['id'], 0, $this->temporary('{}')));
    }

    public function test_same_size_source_corruption_is_detected_by_the_chunk_receipt_before_data_is_returned(): void
    {
        $info = $this->stage('[{"id":741}]');
        $path = $this->jobPath($info, 'source.json');
        $mtime = filemtime($path);
        chmod($path, 0600);
        file_put_contents($path, '[{"id":742}]');
        touch($path, $mtime);
        $this->reason('json_source_changed', fn () => $this->source->readBatch(73, $info['id']));
    }

    public function test_receipt_corruption_and_source_size_changes_are_rejected(): void
    {
        $info = $this->stage('[{"id":741}]');
        $path = $this->jobPath($info, 'chunks.sha256');
        chmod($path, 0600);
        file_put_contents($path, str_repeat('x', 32));
        $this->reason('json_source_changed', fn () => $this->source->info(73, $info['id']));
        $other = $this->stage('[{"id":742}]');
        $path = $this->jobPath($other, 'source.json');
        chmod($path, 0600);
        file_put_contents($path, ' changed', FILE_APPEND);
        $this->reason('json_source_changed', fn () => $this->source->info(73, $other['id']));
    }

    public function test_finalize_is_idempotent_and_can_resume_after_a_crash_between_rename_and_manifest_commit(): void
    {
        $json = '[{"id":741}]';
        $info = $this->source->create(73, 'alta', 'all.json', strlen($json));
        $this->source->append(73, $info['id'], 0, $this->temporary($json));
        rename($this->jobPath($info, 'upload.part'), $this->jobPath($info, 'source.json'));
        $ready = $this->source->finalize(73, $info['id']);
        self::assertSame($ready, $this->source->finalize(73, $info['id']));
        self::assertTrue($this->source->readBatch(73, $info['id'])['eof']);
    }

    public function test_expired_sources_are_rejected_and_bounded_cleanup_preserves_fresh_or_unknown_files(): void
    {
        $expired = $this->stage('[{"id":741}]');
        $unknown = $this->source->create(73, 'alta', 'unknown.json', 2);
        file_put_contents($this->jobPath($unknown, 'do-not-delete.txt'), 'unrelated');
        Carbon::setTestNow(now()->addSeconds(JsonImportSource::TTL_SECONDS + 1));
        $this->reason('json_source_expired', fn () => $this->source->info(73, $expired['id']));
        $fresh = $this->stage('[{"id":742}]');
        self::assertSame(1, $this->source->purgeExpired());
        self::assertFalse(is_dir(dirname($this->jobPath($expired, 'meta.json'))));
        self::assertSame('ready', $this->source->info(73, $fresh['id'])['state']);
        self::assertFileExists($this->jobPath($unknown, 'do-not-delete.txt'));
    }

    public function test_cleanup_scan_cursor_advances_and_wraps_without_starving_later_directories(): void
    {
        $first = $this->stage('[{"id":741}]');
        $second = $this->stage('[{"id":742}]');
        Carbon::setTestNow(now()->addSeconds(JsonImportSource::TTL_SECONDS + 1));
        self::assertSame(1, $this->source->purgeExpired(1));
        self::assertFileExists($this->directory.'/private/.purge-offset');
        $this->source->purgeExpired(1);
        $this->source->purgeExpired(1);
        self::assertFalse(is_dir(dirname($this->jobPath($first, 'meta.json'))));
        self::assertFalse(is_dir(dirname($this->jobPath($second, 'meta.json'))));
    }
}
