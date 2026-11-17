<?php

namespace App\Services\Warranties;

use App\Models\User;
use App\Models\WarrantyCertificate;

/** Serial-only delegation; all approval, issuance and frozen-document guards stay in the manager. */
class WarrantyCourierSerial
{
    public function __construct(private WarrantyCertificateManager $manager) {}

    public function releaseState(WarrantyCertificate $certificate): array
    {
        return $this->manager->courierSerialReleaseState($certificate);
    }

    public function finalize(WarrantyCertificate $certificate, User $courier, int $deliveryId,
        string $serialNumber, string $expectedReleaseHash): WarrantyCertificate
    {
        return $this->manager->finalizeCourierSerial($certificate, $courier, $deliveryId, $serialNumber, $expectedReleaseHash);
    }
}
