<?php

namespace App\Console\Commands;

use App\Services\Sender\SmsOffice;
use App\Support\Phone;
use Illuminate\Console\Command;

class TestSms extends Command
{
    protected $signature = 'sms:test
                            {phone : the number to text}
                            {--text= : what to send}';

    protected $description = 'Send one text message through smsoffice and report the result';

    public function handle(SmsOffice $sms): int
    {
        $phone = (string) $this->argument('phone');
        $number = Phone::digits($phone);

        $this->table([], [
            ['gateway', config('smsoffice.url')],
            ['sender', config('smsoffice.sender') ?: '— not set —'],
            ['key', config('smsoffice.api_key') ? 'set' : '— not set —'],
            ['enabled', config('smsoffice.enabled') ? 'yes' : 'no'],
            ['redirect to', config('smsoffice.redirect_to') ?: '—'],
            ['dialling', $number ?? '— unreadable —'],
        ]);

        if (! $sms->enabled()) {
            $this->error('Nothing would be sent: smsoffice is off, or the key or sender name is missing.');
            $this->line('  Set SMSOFFICE_ENABLED, SMSOFFICE_KEY and SMSOFFICE_SENDER in .env.');

            return self::FAILURE;
        }

        $text = (string) ($this->option('text') ?: 'IAPI.GE — სატესტო შეტყობინება.');

        if ($sms->send($phone, $text)) {
            $this->info('Sent. The gateway accepted it.');

            return self::SUCCESS;
        }

        $this->error('Not sent. The reason is in storage/logs/sms.log.');

        return self::FAILURE;
    }
}
