<?php

namespace App\Http\Controllers;

use App\Services\Feeds\FacebookFeed;
use App\Services\Feeds\GoogleFeed;
use App\Services\Feeds\ProductFeed;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedController extends Controller
{
    public function facebook(FacebookFeed $feed): BinaryFileResponse
    {
        return $this->serve($feed, 'facebook');
    }

    public function google(GoogleFeed $feed): BinaryFileResponse
    {
        return $this->serve($feed, 'google');
    }

    protected function serve(ProductFeed $feed, string $key): BinaryFileResponse
    {
        $path = Storage::disk('local')->path(config("feeds.{$key}.path"));

        if (! file_exists($path)) {
            $path = $feed->generate();
        }

        return response()->file($path, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age='.config("feeds.{$key}.cache_ttl"),
        ]);
    }
}
