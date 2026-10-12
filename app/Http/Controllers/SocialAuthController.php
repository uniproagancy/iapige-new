<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signing in with Google or Facebook.
 *
 * The buttons were in the markup from the start and did nothing at all —
 * plain <button> elements with no action behind them. Apple is gone rather
 * than left as a fourth: it costs a paid developer account and a signing
 * key, and an unimplemented button is worse than no button.
 */
class SocialAuthController extends Controller
{
    /** Only these, and only when somebody has configured them. */
    protected const PROVIDERS = ['google', 'facebook'];

    public function redirect(string $provider): RedirectResponse
    {
        $this->guard($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->guard($provider);

        try {
            $account = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            /*
             * A cancelled sign-in arrives here exactly as a broken one does,
             * and neither is worth a stack trace in the user's face.
             */
            Log::warning('social sign-in failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('home')->with('error', __('auth.social_failed'));
        }

        if (! $account->getEmail()) {
            /*
             * Facebook returns no address when the person has only a phone
             * number on their account, or has refused the email permission.
             * Without one there is nothing to attach an order to.
             */
            return redirect()->route('home')->with('error', __('auth.social_no_email'));
        }

        Auth::login($this->user($provider, $account), remember: true);

        return redirect()->intended(route('account'));
    }

    /**
     * The person behind the account, found or made.
     *
     * Three cases, in order: we have seen this exact account before; we have
     * their address from an ordinary sign-up; or they are new.
     *
     * The middle one is the careful one. Attaching a Google account to an
     * existing address is right — it is the same person coming back a
     * different way — but only because the provider has already proved the
     * address belongs to them. That is also why the row is locked while this
     * happens: two tabs, two callbacks and one new account each.
     */
    protected function user(string $provider, SocialUser $account): User
    {
        return DB::transaction(function () use ($provider, $account) {
            $existing = User::where('provider', $provider)
                ->where('provider_id', $account->getId())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $byEmail = User::where('email', $account->getEmail())->lockForUpdate()->first();

            if ($byEmail) {
                $byEmail->forceFill([
                    'provider' => $provider,
                    'provider_id' => $account->getId(),
                    'avatar' => $byEmail->avatar ?: $account->getAvatar(),
                    // the provider vouches for the address, which is the point of this
                    'email_verified_at' => $byEmail->email_verified_at ?? now(),
                ])->save();

                return $byEmail;
            }

            return User::forceCreate([
                'name' => $account->getName() ?: Str::before($account->getEmail(), '@'),
                'email' => $account->getEmail(),
                'provider' => $provider,
                'provider_id' => $account->getId(),
                'avatar' => $account->getAvatar(),
                'email_verified_at' => now(),
                // no password at all, rather than one nobody can ever use
                'password' => null,
            ]);
        });
    }

    /**
     * A provider name arrives in the URL, so it is checked rather than trusted,
     * and one with no credentials behind it is treated as not existing.
     */
    protected function guard(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! config("services.{$provider}.client_id")) {
            throw new NotFoundHttpException;
        }
    }
}
