<?php

declare(strict_types=1);

namespace SocialTests;

use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;

class SocialSchemaTest extends TestCase
{
    public function test_mysql_creation_uses_datetimes_without_implicit_timestamp_updates(): void
    {
        $connection = new class(null, 'synthetic', '', ['driver' => 'mysql']) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.0';
            }
        };
        $connection->useDefaultSchemaGrammar();
        $statements = [];
        Schema::shouldReceive('create')->once()->with('social_posts', Mockery::on(function ($callback) use ($connection, &$statements) {
            $blueprint = new Blueprint($connection, 'social_posts');
            $blueprint->create();
            $callback($blueprint);
            $statements = $blueprint->toSql();

            return true;
        }));
        (require SOCIAL_TEST_SOURCE_ROOT.'/database/migrations/2026_10_29_090000_create_social_posts_table.php')->up();
        $sql = strtolower(implode("\n", $statements));

        foreach (['scheduled_for', 'claimed_at', 'published_at', 'created_at', 'updated_at'] as $column) {
            $this->assertStringContainsString(chr(96).$column.chr(96).' datetime', $sql);
        }
        $this->assertStringNotContainsString(' timestamp', $sql);
        $this->assertStringNotContainsString('on update', $sql);
        $this->assertStringNotContainsString('default current_timestamp', $sql);
    }

    public function test_forward_normalization_preserves_slots_and_is_safe_to_leave_on_rollback(): void
    {
        $post = $this->post($this->product(), [
            'scheduled_for' => CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            'scheduled_date' => '2026-10-29',
        ]);
        $before = $post->fresh()->getAttributes();
        $migration = require SOCIAL_TEST_SOURCE_ROOT.'/database/migrations/2026_10_29_110000_normalize_social_post_datetimes.php';
        $migration->up();
        $migration->down();

        $this->assertSame($before, $post->fresh()->getAttributes());
        $columns = collect(Schema::getColumns('social_posts'))->keyBy('name');
        foreach (['scheduled_for', 'claimed_at', 'published_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame('datetime', strtolower($columns[$column]['type_name']));
        }
    }
}
