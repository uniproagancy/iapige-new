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

class SocialAuthController extends Controller
{
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

            Log::warning('social sign-in failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('home')->with('error', __('auth.social_failed'));
        }

        if (! $account->getEmail()) {

            return redirect()->route('home')->with('error', __('auth.social_no_email'));
        }

        Auth::login($this->user($provider, $account), remember: true);

        return redirect()->intended(route('account'));
    }

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

                'password' => null,
            ]);
        });
    }

    protected function guard(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! config("services.{$provider}.client_id")) {
            throw new NotFoundHttpException;
        }
    }
}
