<?php

namespace App\Services\Warranties;

use App\Models\WarrantyCertificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WarrantyCertificateFiles
{
    public function path(WarrantyCertificate $certificate): string
    {
        if (! preg_match('~\Awarranties/certificates/[0-9A-HJKMNP-TV-Z]{26}\.pdf\z~D', (string) $certificate->pdf_path)) {
            throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_integrity')]);
        }
        $disk = Storage::disk(config('warranty.disk', 'local'));
        $root = realpath($disk->path(''));
        $path = is_string($certificate->pdf_path) ? realpath($disk->path($certificate->pdf_path)) : false;
        $storage = realpath(storage_path('app'));
        $public = realpath(public_path());
        if ($root === false || $path === false || $storage === false || ! is_file($path)
            || ! WarrantyPrivateStorage::within($path, $root)
            || ! WarrantyPrivateStorage::within($root, $storage)
            || ($public !== false && WarrantyPrivateStorage::within($path, $public))
            || ! is_string($certificate->pdf_sha256) || ! hash_equals($certificate->pdf_sha256, hash_file('sha256', $path))
            || ! is_array($certificate->snapshot) || ! is_string($certificate->snapshot_sha256)
            || ! hash_equals($certificate->snapshot_sha256, app(WarrantySnapshotBuilder::class)->hash($certificate->snapshot))) {
            throw ValidationException::withMessages(['certificate' => __('warranty.errors.certificate_integrity')]);
        }
        return $path;
    }

    public function bytes(WarrantyCertificate $certificate): string
    {
        return file_get_contents($this->path($certificate));
    }
}
