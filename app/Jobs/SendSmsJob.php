<?php

namespace App\Jobs;

use App\Services\Sender\SmsOffice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [60, 300];

    public function __construct(
        public string $phone,
        public string $text,
    ) {}

    public function handle(SmsOffice $sms): void
    {
        $sms->send($this->phone, $this->text);
    }

    public function uniqueId(): string
    {
        return md5($this->phone.$this->text);
    }
}
