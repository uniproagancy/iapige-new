<?php

namespace App\Console\Commands;

use App\Services\Feeds\FacebookFeed;
use Illuminate\Console\Command;

class GenerateFeed extends Command
{
    protected $signature = 'feed:generate {--facebook : only the Facebook feed}';

    protected $description = 'Rebuild the product feeds';

    public function handle(FacebookFeed $facebook): int
    {
        $this->info('Facebook feed…');

        $path = $facebook->generate();

        $this->info('  written to '.$path.' ('.number_format(filesize($path) / 1024, 0).' KB)');

        return self::SUCCESS;
    }
}
