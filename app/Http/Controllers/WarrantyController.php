<?php

namespace App\Http\Controllers;

use App\Models\WarrantyCertificate;
use App\Models\WarrantyDocument;
use App\Services\Warranties\WarrantyCertificateFiles;
use App\Services\Warranties\WarrantyDocumentStore;
use App\Services\Warranties\WarrantyPdf;
use App\Services\Warranties\WarrantySms;
use App\Services\Warranties\WarrantySnapshotBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WarrantyController extends Controller
{
    public function __construct(
        private WarrantyPdf $pdf,
        private WarrantySnapshotBuilder $snapshots,
        private WarrantyCertificateFiles $files,
        private WarrantyDocumentStore $documents,
    ) {}

    public function preview(Request $request, WarrantyCertificate $certificate): Response|BinaryFileResponse
    {
        $this->authorizeStaff($request);
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            return $this->frozenPdf($certificate, false);
        }

        return response($this->pdf->bytes($this->snapshots->draft($certificate)), 200, $this->headers() + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($certificate).'"',
        ]);
    }

    public function print(Request $request, WarrantyCertificate $certificate): Response|BinaryFileResponse
    {
        $this->authorizeStaff($request);
        // Issued documents are never recreated from a current policy or catalog record.
        if ($certificate->status !== WarrantyCertificate::DRAFT) {
            return $this->frozenPdf($certificate, false);
        }

        return response()->view('warranties.print',
            $this->pdf->viewData($this->snapshots->draft($certificate), false, true),
            200, $this->headers());
    }

    public function pdf(Request $request, WarrantyCertificate $certificate): BinaryFileResponse
    {
        $this->authorizeStaff($request);
        abort_if($certificate->status === WarrantyCertificate::DRAFT, 404);

        return $this->frozenPdf($certificate, true);
    }

    public function document(Request $request, WarrantyDocument $document): BinaryFileResponse
    {
        $actor = $this->authorizeStaff($request);
        // Operators may read approved terms and fill supplier blanks; raw research stays owner-only.
        $approvedPolicyDocument = in_array($document->role, ['official_form', 'terms'], true) && $document->policy_id !== null
            && $document->policy?->status === 'approved';
        abort_unless($actor->isAdmin() || ($document->certificate_id !== null
            && $document->role === 'completed_original') || $approvedPolicyDocument, 403);

        $extension = $document->mime_type === 'application/pdf' ? 'pdf' : 'docx';

        $response = response()->download($this->documents->path($document),
            'warranty-document-'.$document->id.'.'.$extension,
            $this->headers() + ['Content-Type' => $document->mime_type]);

        return $response->setPrivate();
    }

    public function sms(Request $request, WarrantyCertificate $certificate, WarrantySms $sms): RedirectResponse
    {
        $actor = $this->authorizeStaff($request);
        $input = $request->validate([
            'idempotency_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/D'],
            'previous_attempt_id' => ['nullable', 'integer', 'min:1'],
        ]);
        // Recipient, message and link are selected by the service from the frozen certificate.
        $message = isset($input['previous_attempt_id'])
            ? $sms->resend($certificate, $actor, $input['idempotency_key'], (int) $input['previous_attempt_id'])
            : $sms->send($certificate, $actor, $input['idempotency_key']);

        return redirect()->route('admin.warranties.show', $certificate)
            ->with('status', __('warranty.sms_result.'.$message->status));
    }

    protected function frozenPdf(WarrantyCertificate $certificate, bool $download): BinaryFileResponse
    {
        $path = $this->files->path($certificate);
        $response = response()->file($path, $this->headers() + ['Content-Type' => 'application/pdf']);
        $response->setContentDisposition($download ? 'attachment' : 'inline', $this->pdf->filename($certificate));

        return $response->setPrivate();
    }

    protected function authorizeStaff(Request $request): \App\Models\User
    {
        $actor = $request->user();
        abort_unless(config('warranty.enabled') && $actor?->isStaff(), 403);

        return $actor;
    }

    protected function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, no-transform, max-age=0', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"];
    }
}
