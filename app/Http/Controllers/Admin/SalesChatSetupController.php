<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SalesChat\SalesChatSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class SalesChatSetupController extends Controller
{
    public function show(SalesChatSettings $settings): Response
    {
        return response()->view('admin.sales-chat.setup', [
            'enabled' => $settings->enabled(),
            'hasKey' => $settings->hasKey(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function activate(Request $request, SalesChatSettings $settings): RedirectResponse
    {
        $input = ($request->isJson() ? $request->json() : $request->request)->all()['password'] ?? null;
        $request->request->remove('password');
        $request->query->remove('password');
        if ($request->isJson()) {
            $request->json()->remove('password');
        }
        $key = is_string($input) ? trim($input) : '';
        if ($key === '') {
            $key = $settings->key();
        }
        if (! is_string($key) || ! preg_match('/\Ask-[A-Za-z0-9_-]{24,512}\z/', $key)) {
            return $this->reject('invalid_key');
        }

        try {
            $probe = Http::acceptJson()->withToken($key)->connectTimeout(4)->timeout(18)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('sales-chat.openai_model'),
                    'input' => 'Reply with OK.',
                    'store' => false,
                    'reasoning' => ['effort' => config('sales-chat.reasoning_effort', 'low')],
                    'max_output_tokens' => 128,
                ]);
        } catch (ConnectionException) {
            return $this->reject('connection_failed');
        }
        if (! $probe->successful() || $probe->json('status') !== 'completed') {
            return $this->reject('connection_failed');
        }

        $settings->enable($key);

        return to_route('admin.sales-chat.setup.show')->with('status', __('sales_chat_setup.activated'));
    }

    public function disable(SalesChatSettings $settings): RedirectResponse
    {
        $settings->disable();

        return to_route('admin.sales-chat.setup.show')->with('status', __('sales_chat_setup.disabled'));
    }

    private function reject(string $reason): RedirectResponse
    {
        return to_route('admin.sales-chat.setup.show')->withErrors(['password' => __('sales_chat_setup.'.$reason)]);
    }
}
