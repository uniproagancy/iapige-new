<?php

namespace App\Http\Controllers;

use App\Models\SocialPublication;
use App\Services\Social\PublicationPreparer;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SocialPublicationMediaController extends Controller
{
    public function __invoke(Request $request, SocialPublication $publication, string $key, PublicationPreparer $preparer): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature() && preg_match('/\A[a-f0-9]{64}\z/D', $key)
            && is_string($publication->media_key) && hash_equals($publication->media_key, $key)
            && $publication->media_expires_at !== null && $publication->media_expires_at->isFuture(), 404);
        try {
            $preparer->assertCurrent($publication);
        } catch (DomainException) {
            abort(404);
        }
        $path = $preparer->imagePath($publication);
        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => $publication->mime_type, 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ])->setPrivate();
    }
}
