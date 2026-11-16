<?php

namespace App\Http\Controllers;

use App\Models\SocialPublication;
use App\Services\Social\SocialFeedLink;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SocialFeedImageController extends Controller
{
    public function __invoke(SocialPublication $publication, string $key, SocialFeedLink $links): BinaryFileResponse
    {
        $path = $links->imagePath($publication, $key);
        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => $publication->mime_type, 'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ])->setPublic();
    }
}
