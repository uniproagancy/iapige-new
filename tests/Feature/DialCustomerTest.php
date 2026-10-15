<?php

namespace Tests\Feature;

use App\Livewire\Admin\Callbacks\Index;
use App\Models\CallbackRequest;
use App\Models\Language;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Calling the customer from the admin.
 *
 * The operator has a softphone open beside the browser, so the button is an
 * ordinary link with a scheme the softphone registered: the browser hands the
 * address over and Zoiper dials it. Which scheme that is belongs in .env —
 * Zoiper takes tel and callto, and something else may already have tel.
 *
 * The cleaning is the part worth pinning down. People write their number the
 * way they say it, and a softphone dials the string exactly as handed over:
 * "599 12 34 56" reaches nobody.
 */
class DialCustomerTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ the number */

    public function test_the_spaces_people_type_are_taken_out(): void
    {
        $this->assertSame('+995599123456', Phone::dial('599 12 34 56'));
    }

    public function test_brackets_and_dashes_go_too(): void
    {
        $this->assertSame('+995322121028', Phone::dial('(032) 212-10-28'));
    }

    /**
     * The zero in front is how you reach another city from a telephone here,
     * not part of the number — and people write it on mobiles too.
     */
    public function test_the_trunk_zero_is_not_dialled(): void
    {
        $this->assertSame('+995599123456', Phone::dial('0599 12 34 56'));
    }

    /** A number that carries its own country code is already complete. */
    public function test_a_number_with_a_country_code_is_left_alone(): void
    {
        $this->assertSame('+995599123456', Phone::dial('+995 599 123456'));
    }

    public function test_the_country_code_without_a_plus_gets_one(): void
    {
        $this->assertSame('+995599123456', Phone::dial('995599123456'));
    }

    /** "00" is how the same number is written for a landline keypad. */
    public function test_a_double_zero_prefix_becomes_a_plus(): void
    {
        $this->assertSame('+995599123456', Phone::dial('00995599123456'));
    }

    public function test_a_foreign_number_keeps_its_own_code(): void
    {
        $this->assertSame('+447700900123', Phone::dial('+44 7700 900123'));
    }

    public function test_nothing_dialable_gives_null(): void
    {
        $this->assertNull(Phone::dial(''));
        $this->assertNull(Phone::dial(null));
        $this->assertNull(Phone::dial('—'));
        $this->assertNull(Phone::dial('+'));
    }

    /* ------------------------------------------------------------------ the scheme */

    public function test_the_scheme_comes_from_the_configuration(): void
    {
        config(['shop.dial_scheme' => 'sip']);

        $this->assertSame('sip:+995599123456', Phone::link('599 12 34 56'));
    }

    public function test_it_dials_with_tel_unless_told_otherwise(): void
    {
        config(['shop.dial_scheme' => null]);

        $this->assertSame('tel:+995599123456', Phone::link('599123456'));
    }

    public function test_a_number_that_cannot_be_dialled_has_no_link(): void
    {
        $this->assertNull(Phone::link('—'));
    }

    /* ------------------------------------------------------------------ the button */

    public function test_the_screen_offers_to_call(): void
    {
        $this->request('599 12 34 56');

        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee(__('admin.cb_call'))
            ->assertSee('tel:+995599123456');
    }

    /** Whatever the operator's softphone registered. */
    public function test_the_button_follows_the_configured_scheme(): void
    {
        config(['shop.dial_scheme' => 'callto']);

        $this->request('599 12 34 56');

        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee('callto:+995599123456');
    }

    /**
     * Calling does not answer the call.
     *
     * The line may be busy or nobody may pick up, and a row marked "called"
     * on the strength of the button being pressed is a lead quietly dropped.
     */
    public function test_calling_does_not_mark_the_request_as_handled(): void
    {
        $request = $this->request('599 12 34 56');

        Livewire::actingAs($this->admin())->test(Index::class)->assertOk();

        $this->assertSame(CallbackRequest::NEW, $request->fresh()->status);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        config(['shop.dial_scheme' => 'tel', 'shop.phone_country' => '995']);
    }

    protected function admin(): User
    {
        return User::create([
            'name' => 'operator', 'email' => 'o'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);
    }

    protected function request(string $phone): CallbackRequest
    {
        return CallbackRequest::create([
            'name' => 'გიორგი',
            'phone' => $phone,
            'status' => CallbackRequest::NEW,
        ]);
    }
}
