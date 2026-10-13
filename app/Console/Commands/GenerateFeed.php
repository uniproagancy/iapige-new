<?php

namespace App\Console\Commands;

use App\Services\Feeds\FacebookFeed;
use App\Services\Feeds\GoogleFeed;
use App\Services\Feeds\ProductFeed;
use Illuminate\Console\Command;

class GenerateFeed extends Command
{
    protected $signature = 'feed:generate
                            {--facebook : only the Facebook feed}
                            {--google : only the Google feed}';

    protected $description = 'Rebuild the product feeds';

    public function handle(FacebookFeed $facebook, GoogleFeed $google): int
    {
        // naming neither means both, which is what the scheduler wants
        $only = $this->option('facebook') || $this->option('google');

        if (! $only || $this->option('facebook')) {
            $this->write('Facebook', $facebook);
        }

        if (! $only || $this->option('google')) {
            $this->write('Google', $google);
        }

        return self::SUCCESS;
    }

    protected function write(string $name, ProductFeed $feed): void
    {
        $this->info($name.' feed…');

        $path = $feed->generate();

        $this->info('  written to '.$path.' ('.number_format(filesize($path) / 1024, 0).' KB)');
    }
}
