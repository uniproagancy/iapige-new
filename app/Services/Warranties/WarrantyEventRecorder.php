<?php

namespace App\Services\Warranties;

use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyEvent;
use App\Models\WarrantyPolicy;

class WarrantyEventRecorder
{
    public function record(string $type, ?User $actor = null, ?WarrantyCertificate $certificate = null, ?WarrantyPolicy $policy = null, array $metadata = []): WarrantyEvent
    {
        // Whitelist audit identifiers; never retain message text, phone numbers or bearer tokens.
        $safe = array_intersect_key($metadata, array_flip(['document_id', 'previous_id', 'revision', 'reason', 'delivery_id', 'status', 'scope', 'reference', 'sms_message_id']));
        return WarrantyEvent::create(['type' => $type, 'actor_id' => $actor?->id,
            'certificate_id' => $certificate?->id, 'policy_id' => $policy?->id, 'metadata' => $safe]);
    }
}
