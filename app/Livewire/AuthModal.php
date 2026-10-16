<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Facebook\Pixel;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AuthModal extends Component
{
    public string $mode = 'login';

    public string $login = '';

    public string $password = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $remember = false;

    public bool $terms = false;

    public string $status = '';

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['login', 'register', 'forgot'], true) ? $mode : 'login';
        $this->status = '';
        $this->resetValidation();
    }

    public function submit(): void
    {
        match ($this->mode) {
            'register' => $this->register(),
            'forgot' => $this->sendResetLink(),
            default => $this->signIn(),
        };
    }

    protected function signIn(): void
    {
        $this->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        $credentials = [$this->loginField() => $this->loginValue(), 'password' => $this->password];

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $this->finish(__('auth.welcome_back', ['name' => Auth::user()->firstName()]));
    }

    protected function loginField(): string
    {
        return filter_var($this->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
    }

    protected function loginValue(): string
    {
        return $this->loginField() === 'email'
            ? Str::lower(trim($this->login))
            : User::normalisePhone($this->login);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->login).'|'.request()->ip());
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    protected function register(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'phone' => ['required', 'string', 'min:9', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'terms' => ['accepted'],
        ]);

        $phone = User::normalisePhone($data['phone']);

        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => __('auth.phone_taken')]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'phone' => $phone,
            'password' => $data['password'],
        ]);

        event(new Registered($user));

        Auth::login($user, true);
        session()->regenerate();

        $this->dispatch('pixel', app(Pixel::class)->completeRegistration($user));

        $this->finish(__('auth.welcome', ['name' => $user->firstName()]));
    }

    protected function sendResetLink(): void
    {
        $this->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(['email' => Str::lower($this->email)]);

        $this->status = __($status === Password::RESET_LINK_SENT ? $status : 'auth.reset_sent');
        $this->reset('email');
    }

    protected function finish(string $message): void
    {
        $this->reset(['login', 'password', 'name', 'email', 'phone', 'terms']);

        $this->redirect(url()->previous() ?: route('home'), navigate: false);

        session()->flash('toast', $message);
    }

    public function render()
    {
        return view('livewire.auth-modal');
    }
}
