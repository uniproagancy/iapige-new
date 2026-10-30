<?php

namespace App\Http\Controllers;

use App\Services\Social\BusinessSuitePreparer;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BusinessSuiteBundleController extends Controller
{
    public function asset(Request $request, string $bundle, string $format, BusinessSuitePreparer $preparer): BinaryFileResponse
    {
        Gate::authorize('owner');
        try {
            $asset = $preparer->asset($bundle, (int) Auth::id(), $format);
        } catch (DomainException $exception) {
            abort(match ($exception->getMessage()) {
                'bundle_expired' => 410,
                'bundle_stale', 'product_unavailable', 'post_not_preparable' => 409,
                default => 404,
            });
        }

        $headers = ['Content-Type' => $asset['mime'], 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'];

        return ($request->boolean('download')
            ? response()->download($asset['path'], $asset['filename'], $headers)
            : response()->file($asset['path'], $headers))->setPrivate();
    }
}
