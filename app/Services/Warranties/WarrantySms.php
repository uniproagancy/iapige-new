<?php

namespace App\Services\Warranties;

use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantySmsMessage;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarrantySms
{
    public function __construct(
        private WarrantyLink $links,
        private WarrantySmsGateway $gateway,
        private WarrantyEventRecorder $events,
        private WarrantyCertificateFiles $files,
        private WarrantyCourierAccess $courierAccess,
    ) {}

    public function send(WarrantyCertificate $certificate, User $actor, string $idempotencyKey): WarrantySmsMessage
    {
        abort_unless($actor->isStaff(), 403);

        return $this->dispatch($certificate, $actor, $idempotencyKey);
    }

    public function sendForCourier(WarrantyCertificate $certificate, User $actor, int $deliveryId, string $idempotencyKey): WarrantySmsMessage
    {
        abort_unless($actor->isCourier(), 403);

        return $this->dispatch($certificate, $actor, $idempotencyKey, $deliveryId);
    }

    public function resend(WarrantyCertificate $certificate, User $actor, string $idempotencyKey, int $previousAttemptId): WarrantySmsMessage
    {
        abort_unless($actor->isStaff(), 403);

        return $this->dispatch($certificate, $actor, $idempotencyKey, null, $previousAttemptId);
    }

    public function resendForCourier(WarrantyCertificate $certificate, User $actor, int $deliveryId, string $idempotencyKey, int $previousAttemptId): WarrantySmsMessage
    {
        abort_unless($actor->isCourier(), 403);

        return $this->dispatch($certificate, $actor, $idempotencyKey, $deliveryId, $previousAttemptId);
    }

    /** Eligibility is advisory for the UI; dispatch always rechecks it under the certificate lock. */
    public function resendState(?WarrantySmsMessage $attempt): array
    {
        if (! $attempt) { return ['canResend' => false, 'retryAfterSeconds' => 0, 'reason' => null]; }
        if (in_array($attempt->status, ['rejected', 'failed'], true)) {
            return ['canResend' => true, 'retryAfterSeconds' => 0, 'reason' => null];
        }
        $inProgress = in_array($attempt->status, ['pending', 'sending'], true);
        if (! $inProgress && ! in_array($attempt->status, ['accepted', 'unknown'], true)) {
            return ['canResend' => false, 'retryAfterSeconds' => 0, 'reason' => 'sms_attempt_changed'];
        }
        $seconds = $inProgress ? 300 : 60;
        $started = ($attempt->status === 'accepted' ? $attempt->accepted_at : null)
            ?? $attempt->attempted_at ?? $attempt->created_at;
        // A partially loaded or legacy undated record must not become an immediate retry.
        $remaining = $started ? max(0, (int) ceil($started->getTimestamp() + $seconds - now()->getTimestamp())) : $seconds;

        return ['canResend' => $remaining === 0, 'retryAfterSeconds' => $remaining,
            'reason' => $remaining === 0 ? null : ($inProgress ? 'sms_in_progress' : 'sms_resend_cooldown')];
    }

    private function dispatch(WarrantyCertificate $certificate, User $actor, string $idempotencyKey, ?int $courierDeliveryId = null,
        ?int $previousAttemptId = null): WarrantySmsMessage
    {
        if (! config('warranty.enabled') || ! config('warranty.sms_enabled') || ! config('warranty.public_enabled')) {
            $this->fail('sms_disabled');
        }
        if (! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/D', $idempotencyKey)) {
            $this->fail('idempotency_required');
        }
        if (! $this->gateway->ready()) {
            $this->fail('sms_gateway_disabled');
        }

        $created = false;
        $message = DB::transaction(function () use ($certificate, $actor, $idempotencyKey, $courierDeliveryId, $previousAttemptId, &$created): WarrantySmsMessage {
            $current = WarrantyCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($courierDeliveryId !== null) {
                // Recheck current assignment and issuance before even reusing a previous attempt.
                $this->courierAccess->authorizeCertificate($current, $actor, $courierDeliveryId, true);
            }
            $previous = WarrantySmsMessage::query()->where('warranty_certificate_id', $current->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($previous && $previousAttemptId === null) {
                return $previous;
            }
            if ($current->status !== WarrantyCertificate::ISSUED || $current->revoked_at !== null) {
                $this->fail('not_issued');
            }
            $this->files->path($current);
            $snapshot = $current->snapshot;
            $recipient = Phone::digits($snapshot['customer']['phone'] ?? null);
            $order = $current->order;
            if (! is_string($recipient) || ! preg_match('/\A9955[0-9]{8}\z/D', $recipient)
                || ! $order || $order->trashed() || Phone::digits($order->phone) !== $recipient) {
                $this->fail('recipient_mismatch');
            }
            if ($previous) {
                // Explicit resend double-clicks reuse the result, with current issuance/source guards retained.
                return $previous;
            }
            if ($previousAttemptId !== null) {
                $latest = WarrantySmsMessage::query()->where('warranty_certificate_id', $current->id)
                    ->orderByDesc('id')->lockForUpdate()->first();
                if ($previousAttemptId < 1 || ! $latest || (int) $latest->id !== $previousAttemptId) {
                    $this->fail('sms_attempt_changed');
                }
                $state = $this->resendState($latest);
                if (! $state['canResend']) { $this->fail($state['reason'] ?? 'sms_attempt_changed'); }
            } elseif (WarrantySmsMessage::query()->where('warranty_certificate_id', $current->id)
                ->whereIn('status', ['pending', 'sending', 'accepted', 'unknown'])->exists()) {
                $this->fail('sms_already_attempted');
            }
            $url = $this->links->url($current);
            $text = __('warranty.sms.message', ['number' => $current->number, 'url' => $url], 'ka');
            if (mb_strlen($text) > 1000) {
                $this->fail('sms_too_long');
            }
            $created = true;
            $record = WarrantySmsMessage::create([
                'warranty_certificate_id' => $current->id, 'idempotency_key' => $idempotencyKey,
                'recipient' => $recipient, 'message' => $text, 'requested_by' => $actor->id,
                'status' => 'sending', 'attempted_at' => now(),
            ]);
            $this->events->record($previousAttemptId === null ? 'sms_requested' : 'sms_resend_requested', $actor, $current,
                metadata: array_filter(['sms_message_id' => $record->id, 'previous_id' => $previousAttemptId], fn ($id) => $id !== null));

            return $record;
        });
        if (! $created) {
            return $message;
        }

        $outcome = $this->gateway->send($message->recipient, $message->message, 'iw'.str_pad((string) $message->id, 16, '0', STR_PAD_LEFT));
        DB::transaction(function () use ($message, $outcome, $actor): void {
            // Match dispatch's certificate -> message lock order, including explicit stale-attempt retries.
            $certificate = WarrantyCertificate::query()->lockForUpdate()->findOrFail($message->warranty_certificate_id);
            $locked = WarrantySmsMessage::query()->lockForUpdate()->findOrFail($message->id);
            $locked->update([
                'status' => $outcome['status'], 'error_code' => $outcome['error'],
                'accepted_at' => $outcome['status'] === 'accepted' ? now() : null,
            ]);
            if ($outcome['status'] === 'accepted') {
                $certificate->forceFill(['last_sms_at' => now()])->save();
            }
            $this->events->record('sms_'.$outcome['status'], $actor, $certificate,
                metadata: ['sms_message_id' => $locked->id, 'status' => $outcome['status']]);
        });

        return $message->refresh();
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['sms' => __('warranty.errors.'.$key)]);
    }
}
