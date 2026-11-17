<?php

namespace App\Services\Warranties;

use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyDocument;
use App\Models\WarrantyPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class WarrantyDocumentStore
{
    public function __construct(private WarrantyEventRecorder $events) {}

    public function forPolicy(WarrantyPolicy $policy, UploadedFile $file, string $role, User $actor): WarrantyDocument
    {
        if (! $actor->exists || ! $actor->isAdmin()) {
            $this->invalid('authorization', 'owner_required');
        }
        if (! in_array($role, ['source', 'official_form', 'terms'], true)) {
            $this->invalid('role', 'document_role_invalid');
        }
        return $this->store($policy, $file, $role, $actor);
    }

    public function forCertificate(WarrantyCertificate $certificate, UploadedFile $file, string $role, User $actor): WarrantyDocument
    {
        if (! $actor->exists || ! $actor->isStaff()) {
            $this->invalid('authorization', 'staff_required');
        }
        if ($role !== 'completed_original') {
            $this->invalid('role', 'document_role_invalid');
        }
        return $this->store($certificate, $file, $role, $actor);
    }

    public function path(WarrantyDocument $document): string
    {
        if (! preg_match('~\Awarranties/documents/[0-9A-HJKMNP-TV-Z]{26}\.(?:pdf|docx)\z~D', $document->path)
            || $document->disk !== config('warranty.disk', 'local')) {
            $this->invalid('document', 'document_integrity');
        }
        $disk = Storage::disk($document->disk);
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($document->path));
        $storage = realpath(storage_path('app'));
        $public = realpath(public_path());
        if ($root === false || $path === false || $storage === false || ! is_file($path)
            || ! WarrantyPrivateStorage::within($path, $root)
            || ! WarrantyPrivateStorage::within($root, $storage)
            || ($public !== false && WarrantyPrivateStorage::within($path, $public))
            || ! hash_equals($document->sha256, hash_file('sha256', $path))
            || (int) filesize($path) !== (int) $document->bytes) {
            $this->invalid('document', 'document_integrity');
        }
        return $path;
    }

    protected function store(WarrantyPolicy|WarrantyCertificate $target, UploadedFile $file, string $role, User $actor): WarrantyDocument
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 20 * 1024 * 1024) {
            $this->invalid('document', 'document_upload_invalid');
        }
        [$extension, $mime] = $this->type($file);
        $diskName = config('warranty.disk', 'local');
        $path = 'warranties/documents/'.Str::ulid().'.'.$extension;
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            $this->invalid('document', 'document_upload_invalid');
        }
        $written = false;
        try {
            return DB::transaction(function () use ($target, $file, $role, $actor, $diskName, $path, $bytes, $mime, &$written): WarrantyDocument {
                $target = $target->newQuery()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                if ($target->status !== 'draft') {
                    $this->invalid('document', 'document_target_immutable');
                }
                $disk = Storage::disk($diskName);
                app(WarrantyPrivateStorage::class)->put($path, $bytes);
                $written = true;
                @chmod($disk->path($path), 0600);
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255);
                $document = WarrantyDocument::create(['policy_id' => $target instanceof WarrantyPolicy ? $target->id : null,
                    'certificate_id' => $target instanceof WarrantyCertificate ? $target->id : null, 'role' => $role,
                    'label' => $name, 'original_name' => $name, 'mime_type' => $mime, 'disk' => $diskName,
                    'path' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'uploaded_by' => $actor->id]);
                $this->path($document);
                $this->events->record('document_uploaded', $actor,
                    $target instanceof WarrantyCertificate ? $target : null, $target instanceof WarrantyPolicy ? $target : null,
                    ['document_id' => $document->id]);
                return $document;
            });
        } catch (\Throwable $error) {
            if ($written) {
                Storage::disk($diskName)->delete($path);
            }
            throw $error;
        }
    }

    protected function type(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'pdf' && str_starts_with((string) file_get_contents($path, false, null, 0, 8), '%PDF-')) {
            return ['pdf', 'application/pdf'];
        }
        if ($extension === 'docx' && class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            if ($zip->open($path) === true) {
                $typesInfo = $zip->statName('[Content_Types].xml');
                $types = is_array($typesInfo) && $typesInfo['size'] <= 1024 * 1024 ? $zip->getFromName('[Content_Types].xml') : false;
                $valid = $zip->locateName('word/document.xml') !== false && is_string($types)
                    && $zip->numFiles <= 10000
                    && str_contains($types, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml');
                for ($i = 0; $valid && $i < $zip->numFiles; $i++) {
                    if (preg_match('~(?:vbaProject\.bin|\A/|(?:\A|/)\.\.(?:/|\z))~i', $zip->getNameIndex($i))) {
                        $valid = false;
                    }
                }
                $zip->close();
                if ($valid) {
                    return ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                }
            }
        }
        $this->invalid('document', 'document_type_invalid');
    }

    protected function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('warranty.errors.'.$key)]);
    }
}
