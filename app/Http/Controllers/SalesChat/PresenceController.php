<?php

namespace App\Http\Controllers\SalesChat;

use App\Http\Controllers\Controller;
use App\Services\SalesChat\SalesChatPresence;
use App\Services\SalesChat\SalesChatSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PresenceController extends Controller
{
    public function __construct(private readonly SalesChatSettings $settings, private readonly SalesChatPresence $presence) {}

    public function show(Request $request): Response
    {
        $this->authorizeStaff($request);

        return response()->view('admin.sales-chat.presence', [
            'presence' => $this->presence->snapshot(),
            'dataUrl' => route('admin.sales-chat.presence.data'),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json($this->presence->snapshot(), 200, ['Cache-Control' => 'private, no-store']);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->can('admin'), 403);
        abort_unless($this->settings->enabled(), 404);
    }
}
