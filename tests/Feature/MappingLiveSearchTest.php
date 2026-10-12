<?php

namespace Tests\Feature;

use App\Livewire\Admin\Mapping\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Choosing a category by typing part of its name.
 *
 * The field began as a select holding every category in the shop, which
 * offered nothing but the browser's jump-to-first-letter. A datalist filtered
 * as you type but drew a box the browser owns — not sizeable, not styleable —
 * and these labels are full paths, so the end of the name was always the part
 * cut off. The list is ours now, and every step of it is a method rather than
 * something only a browser can do.
 */
class MappingLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Category $watches;

    /* ------------------------------------------------------------------ choosing */

    public function test_choosing_from_the_list_saves_it(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        Livewire::test(Index::class)
            ->call('openPicker', $id)
            ->call('choose', $id, $this->watches->id);

        $this->assertSame($this->watches->id, $this->mappedTo($id));
    }

    /** And the list closes behind it, so the next row starts clean. */
    public function test_the_list_closes_after_choosing(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        $component = Livewire::test(Index::class)
            ->call('openPicker', $id)
            ->set('pick', 'საათ')
            ->call('choose', $id, $this->watches->id);

        $this->assertNull($component->get('picking'));
        $this->assertSame('', $component->get('pick'));
    }

    public function test_a_mapping_can_be_removed(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        $component = Livewire::test(Index::class)->call('choose', $id, $this->watches->id);
        $component->call('openPicker', $id)->call('unmap', $id);

        $this->assertNull($this->mappedTo($id));
    }

    /* ------------------------------------------------------------------ searching */

    /**
     * Matched anywhere in the path, not only at the start.
     *
     * The word somebody remembers is the last one — "watches", not "Mobile
     * phones and accessories" — so a list that only matched the beginning was
     * no better than scrolling.
     */
    public function test_the_last_word_of_a_path_finds_it(): void
    {
        $component = Livewire::test(Index::class)->set('pick', 'საათ');

        $this->assertContains('მობილურები › სმარტ საათები', $component->instance()->suggestions());
    }

    public function test_the_search_ignores_case(): void
    {
        $this->category('Accessories', null);

        $component = Livewire::test(Index::class)->set('pick', 'accessories');

        $this->assertContains('Accessories', $component->instance()->suggestions());
    }

    /** Nothing typed is still a list — the first few, to choose from. */
    public function test_an_empty_search_offers_the_first_few(): void
    {
        $this->assertNotEmpty(Livewire::test(Index::class)->set('pick', '')->instance()->suggestions());
    }

    public function test_a_search_that_matches_nothing_offers_nothing(): void
    {
        $this->assertSame([], Livewire::test(Index::class)->set('pick', 'qqqq')->instance()->suggestions());
    }

    /** A dozen at a time, so the page does not become the list. */
    public function test_the_list_is_capped(): void
    {
        foreach (range(1, 30) as $i) {
            $this->category('კატეგორია '.$i, null);
        }

        $this->assertLessThanOrEqual(12, count(Livewire::test(Index::class)->set('pick', 'კატეგორია')->instance()->suggestions()));
    }

    /** Two of the same name are told apart by the path in front of them. */
    public function test_identical_names_are_told_apart_by_their_path(): void
    {
        $other = $this->category('აქსესუარები', null);
        $this->category('სმარტ საათები', $other->id);

        $labels = Livewire::test(Index::class)->set('pick', 'სმარტ საათები')->instance()->suggestions();

        $this->assertContains('მობილურები › სმარტ საათები', $labels);
        $this->assertContains('აქსესუარები › სმარტ საათები', $labels);
    }

    /* ------------------------------------------------------------------ one at a time */

    /** Two half-finished pickers on a screen is how the wrong row gets mapped. */
    public function test_opening_one_row_closes_the_other(): void
    {
        $first = $this->unmapped('ერთი');
        $second = $this->unmapped('ორი');

        $component = Livewire::test(Index::class)
            ->call('openPicker', $first)
            ->set('pick', 'საათ')
            ->call('openPicker', $second);

        $this->assertSame($second, $component->get('picking'));
        $this->assertSame('', $component->get('pick'), 'the search starts clean on the new row');
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $phones = $this->category('მობილურები', null);
        $this->watches = $this->category('სმარტ საათები', $phones->id);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function category(string $name, ?int $parentId): Category
    {
        $category = Category::create(['parent_id' => $parentId, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => $name, 'slug' => str()->slug($name).'-'.$category->id]]);

        return $category->fresh();
    }

    protected function unmapped(string $externalName): int
    {
        return DB::table('supplier_category_map')->insertGetId([
            'supplier_id' => $this->supplier->id,
            'external_name' => $externalName,
            'category_id' => null,
            'hits' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function mappedTo(int $id): ?int
    {
        $value = DB::table('supplier_category_map')->where('id', $id)->value('category_id');

        return $value === null ? null : (int) $value;
    }
}
