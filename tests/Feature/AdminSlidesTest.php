<?php

namespace Tests\Feature;

use App\Livewire\Admin\Slides\Index;
use App\Models\Language;
use App\Models\Slide;
use App\Models\User;
use App\Support\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The home page's carousel, edited rather than deployed.
 *
 * Five slides lived in Support\Store with their pictures fetched from
 * picsum.photos by number, so changing the dates on the banner meant editing
 * PHP — which is why it still said "26—31 August" in the middle of October.
 *
 * The fallback is the part worth pinning: those five still show while nobody
 * has added a real slide, because the carousel is the first thing on the page
 * and an empty one reads as a broken shop rather than an unfinished one. The
 * first real slide takes all five away together.
 */
class AdminSlidesTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ the screen */

    public function test_the_screen_opens(): void
    {
        $this->screen()->assertOk()->assertSee(__('admin.slides'));
    }

    public function test_it_says_when_there_are_none(): void
    {
        $this->screen()->assertSee(__('admin.slides_none'));
    }

    public function test_a_slide_is_created(): void
    {
        $this->screen()
            ->call('create')
            ->set('translations.ka.title', "ზაფხულის\nგაყიდვა")
            ->set('translations.ka.dates', '26—31 აგვისტო')
            ->set('translations.ka.sub', 'NORDA-ს ტექნიკაზე')
            ->set('translations.ka.cta', 'ნახვა')
            ->set('url', 'https://iapi.ge/catalog')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $slide = Slide::withTranslation()->first();

        $this->assertNotNull($slide);
        $this->assertSame("ზაფხულის\nგაყიდვა", $slide->title);
        $this->assertSame('26—31 აგვისტო', $slide->dates);
        $this->assertSame('https://iapi.ge/catalog', $slide->url);
    }

    /** A slide with no words on it is a slide nobody wrote. */
    public function test_a_title_is_required(): void
    {
        $this->screen()
            ->call('create')
            ->call('save')
            ->assertHasErrors('translations.ka.title');

        $this->assertSame(0, Slide::count());
    }

    public function test_a_slide_is_edited(): void
    {
        $slide = $this->slide('ძველი');

        $this->screen()
            ->call('edit', $slide->id)
            ->assertSet('translations.ka.title', 'ძველი')
            ->set('translations.ka.title', 'ახალი')
            ->call('save');

        $this->assertSame('ახალი', $slide->fresh()->title);
        $this->assertSame(1, Slide::count(), 'editing made a second slide');
    }

    public function test_a_slide_is_deleted(): void
    {
        $slide = $this->slide('ერთი');

        $this->screen()->call('delete', $slide->id);

        $this->assertNull(Slide::find($slide->id));
        $this->assertDatabaseMissing('slide_translations', ['slide_id' => $slide->id]);
    }

    public function test_a_slide_is_switched_off_and_on(): void
    {
        $slide = $this->slide('ერთი');

        $this->screen()->call('toggleActive', $slide->id);
        $this->assertFalse($slide->fresh()->is_active);

        $this->screen()->call('toggleActive', $slide->id);
        $this->assertTrue($slide->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ the picture */

    public function test_a_picture_is_uploaded(): void
    {
        Storage::fake('public');

        $this->screen()
            ->call('create')
            ->set('translations.ka.title', 'სათაური')
            ->set('image', UploadedFile::fake()->image('hero.jpg', 1440, 530))
            ->call('save')
            ->assertHasNoErrors();

        $slide = Slide::first();

        $this->assertNotNull($slide->image);
        Storage::disk('public')->assertExists($slide->image);
    }

    /**
     * An SVG is a document that can carry script.
     *
     * The `image` rule lets one through, and this picture is served from our
     * own domain across the whole front page.
     */
    public function test_an_svg_is_refused(): void
    {
        Storage::fake('public');

        $this->screen()
            ->call('create')
            ->set('translations.ka.title', 'სათაური')
            ->set('image', UploadedFile::fake()->create('hero.svg', 10, 'image/svg+xml'))
            ->call('save')
            ->assertHasErrors('image');

        $this->assertSame(0, Slide::count());
    }

    /** Nothing else points at it, so it would sit on the disk for ever. */
    public function test_the_picture_goes_with_the_slide(): void
    {
        Storage::fake('public');

        $this->screen()
            ->call('create')
            ->set('translations.ka.title', 'სათაური')
            ->set('image', UploadedFile::fake()->image('hero.jpg'))
            ->call('save');

        $slide = Slide::first();
        $path = $slide->image;

        $this->screen()->call('delete', $slide->id);

        Storage::disk('public')->assertMissing($path);
    }

    /* ------------------------------------------------------------------ order and dates */

    public function test_a_slide_moves_down_the_carousel(): void
    {
        $first = $this->slide('პირველი', 0);
        $second = $this->slide('მეორე', 1);

        $this->screen()->call('move', $first->id, 1);

        $this->assertSame(
            ['მეორე', 'პირველი'],
            Slide::withTranslation()->orderBy('sort_order')->orderBy('id')->get()->pluck('title')->all(),
        );
        $this->assertNotSame($first->sort_order, $first->fresh()->sort_order);
        $this->assertNotNull($second->fresh());
    }

    public function test_the_first_slide_cannot_move_up(): void
    {
        $first = $this->slide('პირველი', 0);
        $this->slide('მეორე', 1);

        $this->screen()->call('move', $first->id, -1);

        $this->assertSame(
            ['პირველი', 'მეორე'],
            Slide::withTranslation()->orderBy('sort_order')->orderBy('id')->get()->pluck('title')->all(),
        );
    }

    public function test_a_finish_before_the_start_is_refused(): void
    {
        $this->screen()
            ->call('create')
            ->set('translations.ka.title', 'სათაური')
            ->set('starts_at', '2026-12-01T10:00')
            ->set('ends_at', '2026-11-01T10:00')
            ->call('save')
            ->assertHasErrors('ends_at');
    }

    /* ------------------------------------------------------------------ what the shop draws */

    /** Until there is a real one, the carousel is the five it was built with. */
    public function test_the_built_in_slides_show_while_there_are_none(): void
    {
        $slides = Store::slides();

        $this->assertCount(5, $slides);
        $this->assertNull($slides[0]['image']);
    }

    public function test_one_real_slide_replaces_all_of_them(): void
    {
        $this->slide('ერთადერთი');

        $slides = Store::slides();

        $this->assertCount(1, $slides);
        $this->assertSame('ერთადერთი', $slides[0]['title']);
    }

    public function test_a_slide_switched_off_is_not_drawn(): void
    {
        $this->slide('ჩანს');
        $hidden = $this->slide('არ ჩანს');
        $hidden->update(['is_active' => false]);

        $this->assertSame(['ჩანს'], array_column(Store::slides(), 'title'));
    }

    /** A dated slide takes itself down rather than waiting to be noticed. */
    public function test_a_slide_whose_dates_have_passed_is_not_drawn(): void
    {
        $this->slide('ჩანს');
        $over = $this->slide('დასრულდა');
        $over->update(['ends_at' => now()->subDay()]);

        $this->assertSame(['ჩანს'], array_column(Store::slides(), 'title'));
    }

    public function test_a_slide_that_has_not_started_is_not_drawn_yet(): void
    {
        $this->slide('ჩანს');
        $later = $this->slide('მოგვიანებით');
        $later->update(['starts_at' => now()->addDay()]);

        $this->assertSame(['ჩანს'], array_column(Store::slides(), 'title'));
    }

    /**
     * Switching every slide off brings the built-in ones back.
     *
     * The alternative is a blank rectangle at the top of the shop, which is
     * the one outcome worse than a stale slide.
     */
    public function test_switching_them_all_off_falls_back(): void
    {
        $this->slide('ერთი')->update(['is_active' => false]);

        $this->assertCount(5, Store::slides());
    }

    public function test_the_carousel_follows_the_order(): void
    {
        $this->slide('მეორე', 1);
        $this->slide('პირველი', 0);

        $this->assertSame(['პირველი', 'მეორე'], array_column(Store::slides(), 'title'));
    }

    /**
     * A slide with words and no picture draws no picture.
     *
     * The placeholder is addressed by number, so a slide with neither its own
     * image nor a number built "picsum.photos/id//1440/530" — an empty src,
     * which browsers resolve against the page itself and fetch the whole
     * document back as an image.
     */
    public function test_a_slide_without_a_picture_draws_none(): void
    {
        $slide = $this->slide('უსურათო');
        $slide->update(['image' => null]);

        $html = $this->carousel();

        $this->assertStringContainsString('უსურათო', $html);
        $this->assertStringNotContainsString('picsum.photos/id//', $html);
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

    protected function screen()
    {
        $admin = User::create([
            'name' => 'admin', 'email' => 'a'.uniqid().'@iapi.ge',
            'password' => bcrypt('x'), 'is_admin' => true,
        ]);

        return Livewire::actingAs($admin)->test(Index::class);
    }

    protected function carousel(): string
    {
        return view('components.home.hero', ['slides' => Store::slides()])->render();
    }

    protected function slide(string $title, int $order = 0): Slide
    {
        $slide = Slide::create([
            'image' => 'slides/'.str()->random(6).'.jpg',
            'is_active' => true,
            'sort_order' => $order,
        ]);

        $slide->saveTranslations(['ka' => ['title' => $title, 'dates' => '', 'sub' => '', 'cta' => '']]);

        return $slide->fresh();
    }
}
