<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Signing in with Google or Facebook.
 *
 * The three buttons on the form were plain <button> elements with nothing
 * behind them, so the shop offered three ways in and had none. Apple is gone
 * rather than left as a third — it needs a paid developer account — and the
 * other two now work.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'id',
            'services.google.client_secret' => 'secret',
            'services.facebook.client_id' => 'id',
            'services.facebook.client_secret' => 'secret',
        ]);
    }

    /* ------------------------------------------------------------------ signing in */

    public function test_a_new_visitor_gets_an_account(): void
    {
        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');

        $this->get('/auth/google/callback')->assertRedirect(route('account'));

        $user = User::first();

        $this->assertSame('nino@example.com', $user->email);
        $this->assertSame('google', $user->provider);
        $this->assertSame('1001', $user->provider_id);
        $this->assertAuthenticatedAs($user);
    }

    /** A social account has no password, and the column had to allow that. */
    public function test_the_new_account_has_no_password(): void
    {
        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');

        $this->get('/auth/google/callback');

        $this->assertNull(User::first()->password);
    }

    public function test_coming_back_signs_in_the_same_account(): void
    {
        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');
        $this->get('/auth/google/callback');

        $this->post('/logout');

        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');
        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
    }

    /**
     * The same person, arriving a different way.
     *
     * Attaching the provider to an address that signed up normally is right —
     * the provider has already proved the address is theirs — and it is also
     * what stops one person owning two accounts with one email.
     */
    public function test_an_existing_email_gains_the_provider(): void
    {
        $existing = User::create([
            'name' => 'ნინო', 'email' => 'nino@example.com', 'password' => Hash::make('secret'),
        ]);

        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');
        $this->get('/auth/google/callback');

        $existing->refresh();

        $this->assertSame(1, User::count());
        $this->assertSame('google', $existing->provider);
        $this->assertNotNull($existing->password, 'the password they already had still works');
        $this->assertAuthenticatedAs($existing);
    }

    /** Two providers, two people, one shared name is still two accounts. */
    public function test_two_providers_with_different_addresses_stay_apart(): void
    {
        $this->fakeProvider('google', '1001', 'nino@example.com', 'ნინო');
        $this->get('/auth/google/callback');
        $this->post('/logout');

        $this->fakeProvider('facebook', '2002', 'giorgi@example.com', 'გიორგი');
        $this->get('/auth/facebook/callback');

        $this->assertSame(2, User::count());
    }

    /* ------------------------------------------------------------------ refusals */

    /**
     * Facebook returns no address when the person has only a phone number on
     * their account, or refused the email permission.
     */
    public function test_an_account_with_no_email_is_turned_away(): void
    {
        $this->fakeProvider('facebook', '2002', null, 'გიორგი');

        $this->get('/auth/facebook/callback')->assertRedirect(route('home'));

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /** A cancelled sign-in is not an error page. */
    public function test_a_failed_sign_in_sends_them_home(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \RuntimeException('denied'));

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertGuest();
    }

    /** The provider name is in the URL, so it is checked rather than trusted. */
    public function test_an_unknown_provider_is_not_a_route(): void
    {
        $this->get('/auth/twitter/redirect')->assertNotFound();
        $this->get('/auth/apple/callback')->assertNotFound();
    }

    /** One that nobody has configured does not exist either. */
    public function test_a_provider_with_no_credentials_is_not_offered(): void
    {
        config(['services.facebook.client_id' => null]);

        $this->get('/auth/facebook/redirect')->assertNotFound();
    }

    /* ------------------------------------------------------------------ the form */

    public function test_the_form_offers_the_configured_providers(): void
    {
        $html = view('components.layout.auth-socials')->render();

        $this->assertStringContainsString(route('social.redirect', 'google'), $html);
        $this->assertStringContainsString(route('social.redirect', 'facebook'), $html);
    }

    /** Apple was a button that could never do anything. */
    public function test_apple_is_gone(): void
    {
        $this->assertStringNotContainsString('Apple', view('components.layout.auth-socials')->render());
    }

    /** Nothing configured means no "or sign in with" at all. */
    public function test_nothing_is_offered_when_nothing_is_configured(): void
    {
        config(['services.google.client_id' => null, 'services.facebook.client_id' => null]);

        $this->assertSame('', trim(view('components.layout.auth-socials')->render()));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function fakeProvider(string $provider, string $id, ?string $email, string $name): void
    {
        $account = (new SocialiteUser)->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.com/a.jpg',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->andReturn($account);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }
}
