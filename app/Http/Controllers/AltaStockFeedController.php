<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaStockFeed;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AltaStockFeedController extends Controller
{
    public function __invoke(Request $request, AltaStockFeed $feed): JsonResponse
    {
        $supplier = Supplier::where('code', 'alta')->first();
        $expected = $supplier?->config['stock_feed_version'] ?? null;
        $given = $request->query('version');

        // The signed, revocable capability permits this stock projection only.
        // Validate it before querying stock, regardless of the visitor's role.
        if (! is_string($expected) || ! Str::isUuid($expected) || ! is_string($given)
            || ! hash_equals($expected, $given) || ! $request->hasValidSignature(false)) {
            return $this->respond(['error' => 'invalid_stock_feed_link'], 403);
        }

        try {
            return $this->respond($feed->snapshot($supplier));
        } catch (AltaUnavailable $e) {
            return $this->respond(['error' => $e->getMessage()], 503);
        } catch (Throwable) {
            return $this->respond(['error' => 'stock_snapshot_unavailable'], 503);
        }
    }

    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
