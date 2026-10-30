<?php

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Services\Social\PagePublisher;
use App\Services\Social\PostPreparer;
use App\Services\Social\SocialSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SocialPublishingController extends Controller
{
    public function connectToken(Request $request, PagePublisher $publisher): RedirectResponse
    {
        Gate::authorize('owner');
        $token = $request->request->all()['page_token'] ?? null;
        $request->request->remove('page_token');
        if (! is_string($token) || strlen(trim($token)) < 8 || strlen($token) > 16384
            || ! preg_match('/\A[\x21-\x7e]+\z/D', trim($token))) {
            return redirect()->route('admin.social')->with('error', __('social.connection.connection_missing'));
        }

        try {
            $publisher->connectPageToken(trim($token));
        } catch (Throwable $e) {
            $reason = in_array($e->getMessage(), ['wrong_page', 'permission_missing', 'token_expired'], true)
                ? $e->getMessage() : 'connection_failed';

            // Tokens never enter validation error bags, old input or the redirect.
            return redirect()->route('admin.social')->with('error', __('social.connection.'.$reason));
        }

        return redirect()->route('admin.social')->with('success', __('social.connection.connected'));
    }

    public function connect(SocialSettings $settings): RedirectResponse
    {
        Gate::authorize('owner');
        if (! $settings->status()['oauth_ready']) {
            return redirect()->route('admin.social')->with('error', __('social.connection.oauth_unavailable'));
        }
        session()->put('social.facebook_connect_owner', ['id' => (int) Auth::id(), 'at' => now()->timestamp]);

        // Reuse the registered sign-in callback; its separate session marker keeps
        // publishing consent separate from customer sign-in and account creation.
        return Socialite::driver('facebook')
            ->scopes(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])
            ->with(['auth_type' => 'rerequest'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        Gate::authorize('owner');
        $pending = session()->pull('social.facebook_connect_owner');
        abort_unless(is_array($pending) && ($pending['id'] ?? null) === (int) Auth::id()
            && (int) ($pending['at'] ?? 0) >= now()->timestamp - 900, 403);

        try {
            $account = Socialite::driver('facebook')->user();
            app(PagePublisher::class)->connectUserToken((string) $account->token);
        } catch (Throwable $e) {
            $reason = in_array($e->getMessage(), ['wrong_page', 'permission_missing', 'token_expired', 'oauth_unavailable'], true)
                ? $e->getMessage() : 'connection_failed';

            return redirect()->route('admin.social')->with('error', __('social.connection.'.$reason));
        }

        return redirect()->route('admin.social')->with('success', __('social.connection.connected'));
    }

    public function artwork(SocialPost $post, PostPreparer $preparer): BinaryFileResponse
    {
        Gate::authorize('owner');
        abort_unless($post->page_id === SocialSettings::PAGE_ID, 404);
        $path = $preparer->imagePath($post);
        abort_unless($path !== null, 404);

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store'])->setPrivate();
    }
}
