<?php

namespace App\Http\Controllers;

use App\Services\SalesChat\SalesChatPresence;
use App\Services\SalesChat\SalesChatSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesChatPresenceController extends Controller
{
    public function heartbeat(Request $request, SalesChatSettings $settings, SalesChatPresence $presence): Response|JsonResponse
    {
        abort_unless($settings->enabled(), 404);
        $validator = Validator::make($request->only(['section', 'product_slug']), [
            'section' => ['required', 'string', Rule::in(SalesChatPresence::SECTIONS)],
            'product_slug' => ['nullable', 'string', 'max:190', 'regex:'.SalesChatPresence::SLUG_PATTERN],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422, ['Cache-Control' => 'private, no-store']);
        }

        $input = $validator->validated();
        $presence->record($request->session()->getId(), $input['section'], $input['product_slug'] ?? null);

        return response()->noContent(204, ['Cache-Control' => 'private, no-store']);
    }
}
