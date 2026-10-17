<?php

namespace App\Jobs;

use App\Services\Facebook\Pixel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPixelEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(public string $event, public array $payload) {}

    public function handle(Pixel $pixel): void
    {
        $pixel->deliver($this->event, $this->payload);
    }
}
