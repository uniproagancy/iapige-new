<?php

namespace App\Http\Controllers;

use App\Models\WarrantyCertificate;
use App\Models\WarrantyDocument;
use App\Services\Warranties\WarrantyCertificateFiles;
use App\Services\Warranties\WarrantyDocumentStore;
use App\Services\Warranties\WarrantyLink;
use App\Services\Warranties\WarrantyPdf;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicWarrantyController extends Controller
{
    public function __construct(
        private WarrantyLink $links,
        private WarrantyCertificateFiles $files,
        private WarrantyDocumentStore $documents,
        private WarrantyPdf $pdf,
    ) {}

    public function show(string $token): Response
    {
        $certificate = $this->certificate($token);
        $snapshot = $certificate->snapshot;

        // Explicit customer-safe data, never serialize the model or the complete snapshot.
        return response()->view('warranties.public', [
            'token' => $token,
            'number' => data_get($snapshot, 'certificate.number'),
            'revision' => data_get($snapshot, 'certificate.revision'),
            'itemName' => data_get($snapshot, 'item.name'),
            'deliveryDate' => data_get($snapshot, 'dates.delivery_date'),
            'termText' => data_get($snapshot, 'warranty.term_text'),
            'sellerName' => data_get($snapshot, 'seller.name'),
            'sellerPhone' => data_get($snapshot, 'seller.phone'),
            'sellerEmail' => data_get($snapshot, 'seller.email'),
            'attachments' => array_map(fn (array $form) => [
                'id' => $form['id'], 'label' => $form['label'] ?? __('warranty.public.attachment', [], 'ka'),
            ], $this->listedForms($snapshot)),
        ], 200, $this->headers());
    }

    public function pdf(string $token): BinaryFileResponse
    {
        $certificate = $this->certificate($token);

        $response = response()->download($this->files->path($certificate), $this->pdf->filename($certificate),
            $this->headers() + ['Content-Type' => 'application/pdf']);

        return $response->setPrivate();
    }

    public function document(string $token, string $document): BinaryFileResponse
    {
        $certificate = $this->certificate($token);
        $snapshot = $certificate->snapshot;
        $frozen = collect($this->listedForms($snapshot))->first(fn (array $form) => (string) $form['id'] === $document);
        abort_unless($frozen, 404);
        $record = WarrantyDocument::query()->findOrFail($document);
        $ownsCompleted = $record->role === 'completed_original'
            && (string) $record->certificate_id === (string) $certificate->id;
        $ownsTerms = $record->role === 'terms' && $record->certificate_id === null
            && $record->policy_id !== null
            && (string) $record->policy_id === (string) data_get($snapshot, 'warranty.policy_id');
        abort_unless(($ownsCompleted || $ownsTerms) && $record->role === $frozen['role']
            && $record->mime_type === $frozen['mime_type']
            && is_string($record->sha256) && hash_equals($frozen['sha256'], $record->sha256), 404);

        try {
            $path = $this->documents->path($record);
        } catch (ValidationException|DecryptException $error) {
            abort(404);
        }
        $extension = $frozen['mime_type'] === 'application/pdf' ? 'pdf' : 'docx';

        $response = response()->download($path, 'warranty-attachment-'.$document.'.'.$extension,
            $this->headers() + ['Content-Type' => $frozen['mime_type']]);

        return $response->setPrivate();
    }

    protected function certificate(string $token): WarrantyCertificate
    {
        abort_unless(config('warranty.enabled') && config('warranty.public_enabled')
            && preg_match('/\A[a-f0-9]{64}\z/D', $token), 404);

        try {
            $certificate = $this->links->find($token);
            $this->files->path($certificate); // Verify both PDF and frozen snapshot before rendering anything.

            return $certificate;
        } catch (ValidationException|DecryptException $error) {
            abort(404);
        }
    }

    protected function listedForms(array $snapshot): array
    {
        $forms = $snapshot['original_forms'] ?? [];
        if (! is_array($forms)) {
            return [];
        }

        return array_values(array_filter($forms, fn ($form) => is_array($form)
            && isset($form['id'], $form['role'], $form['sha256'], $form['mime_type'])
            && ctype_digit((string) $form['id'])
            && in_array($form['role'], ['completed_original', 'terms'], true)
            && is_string($form['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $form['sha256'])
            && in_array($form['mime_type'], ['application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)));
    }

    protected function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"];
    }
}
