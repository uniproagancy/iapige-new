<?php

namespace App\Http\Controllers;

use App\Models\WarrantyAgentKey;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class WarrantyAgentKeyController extends Controller
{
    public function index(Request $request): mixed
    {
        $this->authorizeKeys($request);

        return $this->page();
    }

    public function store(Request $request): mixed
    {
        $this->authorizeKeys($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1', 'max:4'],
            'scopes.*' => ['required', Rule::in(['read', 'prepare', 'issue', 'send'])],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);
        $secret = 'iw_'.bin2hex(random_bytes(32));
        WarrantyAgentKey::create([
            'name' => $data['name'], 'token_hash' => hash('sha256', $secret),
            'scopes' => array_values(array_unique($data['scopes'])), 'created_by' => $request->user()->id,
            'expires_at' => filled($data['expires_at'] ?? null) ? $data['expires_at'].' 23:59:59' : null,
        ]);

        // Return the secret once without retaining plaintext in a database-backed flash session.
        return $this->page($secret);
    }

    public function revoke(Request $request, WarrantyAgentKey $key): mixed
    {
        $this->authorizeKeys($request);
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return redirect()->route('admin.warranties.agent-keys')->with('status', __('warranty.agent.revoked'));
    }

    private function authorizeKeys(Request $request): void
    {
        abort_unless(config('warranty.enabled') && config('warranty.agent_enabled'), 404);
        abort_unless($request->user()?->isAdmin(), 403);
    }

    private function page(?string $secret = null): mixed
    {
        $content = view('warranty-admin.agent-keys', [
            'keys' => WarrantyAgentKey::query()->latest('id')->paginate(20),
            'oneTimeSecret' => $secret,
        ])->render();

        // The existing admin layout is a component layout and requires a slot.
        return response()->view('layouts.admin', [
            'title' => __('warranty.agent.title'), 'slot' => new HtmlString($content),
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
