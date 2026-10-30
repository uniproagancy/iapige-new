<?php

namespace App\Console\Commands;

use App\Services\Social\MultiChannelPublisher;
use Illuminate\Console\Command;

class SocialRun extends Command
{
    protected $signature = 'social:run';

    protected $description = 'Prepare and publish the shop Facebook offers at their scheduled times';

    public function handle(MultiChannelPublisher $publisher): int
    {
        $publisher->run();

        return self::SUCCESS;
    }
}
