<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's navigation bar, which had two faults nobody could see past.
 *
 * Every icon was <i data-feather="…">, an empty element until a bundle of
 * ours has loaded, a global of theirs is where we left it and replace() has
 * run over the DOM. Any one of those missing and the menu is a row of words
 * with gaps in it. The shapes are in the markup now, so they are testable,
 * which is the point: an icon that is drawn by script is an icon no test can
 * see either.
 *
 * And the highlighting never worked at all. @activeMenu(...) is not a
 * directive anybody registered, so Blade passed it through untouched and the
 * rendered class attribute read class="nav-item @activeMenu('admin.slides')"
 * — the text itself, on every page, with nothing ever marked.
 */
class AdminMenuTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ the icons */

    public function test_the_icons_are_in_the_markup(): void
    {
        $page = $this->open();

        $page->assertSee('adm-ico', false);
        $page->assertDontSee('data-feather', false);
    }

    /** One per item, so none of them is a gap in the row. */
    public function test_every_top_level_item_has_one(): void
    {
        $html = $this->open()->getContent();

        $items = substr_count($html, 'class="nav-link d-flex align-items-center"')
            + substr_count($html, 'class="dropdown-toggle nav-link d-flex align-items-center"');

        $this->assertGreaterThanOrEqual(7, $items);
        $this->assertGreaterThanOrEqual($items, substr_count($html, 'adm-ico'));
    }

    /* ------------------------------------------------------------------ the highlighting */

    /** The directive that never was, printed into the page as text. */
    public function test_the_unregistered_directive_is_gone(): void
    {
        $this->open()->assertDontSee('@activeMenu', false);
    }

    public function test_the_page_you_are_on_is_marked(): void
    {
        $this->open('admin.slides')->assertSee('nav-item active', false);
    }

    /**
     * And only that one.
     *
     * A bar with everything marked says as little as one with nothing marked.
     */
    public function test_only_one_item_is_marked(): void
    {
        $html = $this->open('admin.slides')->getContent();

        $this->assertSame(1, substr_count($html, 'nav-item active'));
    }

    /* ------------------------------------------------------------------ what was asked for */

    public function test_the_view_site_link_is_gone(): void
    {
        $this->open()->assertDontSee(__('admin.view_site'));
    }

    /** It is still offered where it means something: one product, on the shop. */
    public function test_the_label_is_kept_for_the_product_screens(): void
    {
        $this->assertNotSame('', __('admin.view_site'));
    }

    /* ------------------------------------------------------------------ still a menu */

    public function test_the_sections_are_all_there(): void
    {
        $page = $this->open();

        foreach (['dashboard', 'catalog', 'orders', 'import', 'callbacks', 'slides', 'promotions'] as $key) {
            $page->assertSee(__("admin.{$key}"));
        }
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();
    }

    /**
     * Note the forceFill.
     *
     * is_admin is not in User::$fillable — rightly, since a mass-assignable
     * one is a privilege waiting to be handed out by a form — so passing it
     * to create() drops it in silence and leaves an ordinary customer. These
     * go through the real route and the can:admin gate, which is what makes
     * that visible; the admin screens tested through Livewire::test never hit
     * the middleware and so never noticed.
     */
    protected function open(string $route = 'admin.brands')
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'),
        ]);

        $admin->forceFill(['is_admin' => true])->save();

        return $this->actingAs($admin)->get(route($route))->assertOk();
    }
}
