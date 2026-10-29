<?php

namespace App\Jobs;

use App\Models\OrderPixelData;
use App\Services\Facebook\Pixel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SendPixelEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public ?int $purchaseSnapshotId = null;

    public function __construct(public string $event, public array $payload, ?int $purchaseSnapshotId = null)
    {
        $this->purchaseSnapshotId = $purchaseSnapshotId;
        $this->onQueue('meta');
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(Pixel $pixel): void
    {
        if (! $this->purchaseSnapshotId) {
            if (! $pixel->deliver($this->event, $this->payload)) {
                throw new RuntimeException('Meta event delivery failed; retry with the same event identity.');
            }

            return;
        }

        DB::transaction(function () use ($pixel) {
            $snapshot = OrderPixelData::whereKey($this->purchaseSnapshotId)->lockForUpdate()->first();

            if (! $snapshot || $snapshot->alreadySent()) {
                return;
            }

            if (! $pixel->deliver($this->event, $this->payload)) {
                throw new RuntimeException('Meta purchase delivery failed; retry with the same event identity.');
            }

            $snapshot->markSent();
        });
    }
}
