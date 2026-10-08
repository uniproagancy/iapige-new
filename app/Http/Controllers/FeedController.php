<?php

namespace App\Http\Controllers;

use App\Services\Feeds\FacebookFeed;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the pre-built feed. Facebook fetches it on its own schedule and does
 * not wait politely, so the request must never trigger generation — that is
 * the scheduler's job.
 */
class FeedController extends Controller
{
    public function facebook(FacebookFeed $feed): BinaryFileResponse
    {
        $path = Storage::disk('local')->path(config('feeds.facebook.path'));

        // first ever request, or the file was wiped: build it once
        if (! file_exists($path)) {
            $path = $feed->generate();
        }

        return response()->file($path, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age='.config('feeds.facebook.cache_ttl'),
        ]);
    }
}
