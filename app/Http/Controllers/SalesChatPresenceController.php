<?php

namespace App\Http\Controllers;

use App\Services\SalesChat\SalesChatPresence;
use App\Services\SalesChat\SalesChatSettings;
use App\Services\SalesChat\SalesChatVisitorHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Cookie;

class SalesChatPresenceController extends Controller
{
    private const HISTORY_COOKIE = 'iapi_visitor_history';

    public function heartbeat(
        Request $request,
        SalesChatSettings $settings,
        SalesChatPresence $presence,
        SalesChatVisitorHistory $history,
    ): Response|JsonResponse {
        abort_unless($settings->enabled(), 404);
        $validator = Validator::make($request->only(['section', 'product_slug']), [
            'section' => ['required', 'string', Rule::in(SalesChatPresence::SECTIONS)],
            'product_slug' => ['nullable', 'string', 'max:190', 'regex:'.SalesChatPresence::SLUG_PATTERN],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422, ['Cache-Control' => 'private, no-store']);
        }

        $input = $validator->validated();
        $sessionId = $request->session()->getId();
        $presence->record($sessionId, $input['section'], $input['product_slug'] ?? null);

        $cookie = $request->cookie(self::HISTORY_COOKIE);
        $hasBrowserToken = is_string($cookie) && preg_match('/\A[a-f0-9]{64}\z/', $cookie) === 1;
        $browserToken = $hasBrowserToken ? $cookie : $history->initialBrowserToken($sessionId);
        try {
            $history->record($browserToken);
        } catch (QueryException $error) {
            Log::warning('Could not record a sales chat visitor history entry.', ['sqlstate' => $error->getCode()]);
        }

        $response = response()->noContent(204, ['Cache-Control' => 'private, no-store']);
        if (! $hasBrowserToken) {
            $response->withCookie(Cookie::create(self::HISTORY_COOKIE, $browserToken, now()->addYear())
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
