<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaItemLookup;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 | /admin/alta/item?sku=alta-171840 -- the alta:item check, from a browser.
 |
 | Each request is a live SOAP call to Alta, so it sits behind a throttle;
 | the answer carries Alta's own unit price, so it stays with the owner.
 */
class AltaItemController extends Controller
{
    public function __invoke(Request $request, AltaItemLookup $lookup): JsonResponse
    {
        $item = trim((string) ($request->query('sku') ?? $request->query('item') ?? ''));

        /* The code goes into the SOAP envelope as it is, so only the shape
           an Alta code has is let through. */
        if (! preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $item) || AltaItemLookup::code($item) === '') {
            return $this->respond(['error' => __('admin.alta_item_missing')], 422);
        }

        $supplier = Supplier::where('code', 'alta')->first();

        if (! $supplier) {
            return $this->respond(['error' => __('admin.alta_item_no_supplier')], 404);
        }

        try {
            return $this->respond($lookup->check($supplier, $item));
        } catch (AltaUnavailable $e) {
            return $this->respond(['error' => $e->getMessage()], 503);
        }
    }

    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
