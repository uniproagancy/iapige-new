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
 * Mapping a supplier's category by typing its name.
 *
 * The field is a datalist, so the browser hands back the label and something
 * has to turn it into the category it stands for. That was done in
 * JavaScript — find the option, read an id off it, call the component — which
 * is three places to go wrong and none of them testable. The list of labels
 * is built here, so the lookup is too, and these cover it.
 */
class MappingLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Category $watches;

    public function test_a_chosen_label_is_saved(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        Livewire::test(Index::class)->call('assignByLabel', $id, 'მობილურები › სმარტ საათები');

        $this->assertSame($this->watches->id, $this->mappedTo($id));
    }

    /** A top-level category has no trail in front of it. */
    public function test_a_label_without_a_trail_is_saved(): void
    {
        $id = $this->unmapped('ტელეფონები');

        Livewire::test(Index::class)->call('assignByLabel', $id, 'მობილურები');

        $this->assertSame($this->watches->parent_id, $this->mappedTo($id));
    }

    /**
     * Half a word is somebody still choosing.
     *
     * The field fires on every change, so a name nobody has finished typing
     * must not clear the mapping that is already there.
     */
    public function test_an_unfinished_label_changes_nothing(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        $component = Livewire::test(Index::class);
        $component->call('assignByLabel', $id, 'მობილურები › სმარტ საათები');
        $component->call('assignByLabel', $id, 'მობილუ');

        $this->assertSame($this->watches->id, $this->mappedTo($id), 'a half-typed name is not a choice');
    }

    /** Clearing the field is how a mapping is removed. */
    public function test_an_empty_label_unmaps_it(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        $component = Livewire::test(Index::class);
        $component->call('assignByLabel', $id, 'მობილურები › სმარტ საათები');
        $component->call('assignByLabel', $id, '');

        $this->assertNull($this->mappedTo($id));
    }

    /** Surrounding spaces are the browser's, not part of the name. */
    public function test_a_padded_label_still_matches(): void
    {
        $id = $this->unmapped('სმარტ საათები');

        Livewire::test(Index::class)->call('assignByLabel', $id, '  მობილურები › სმარტ საათები  ');

        $this->assertSame($this->watches->id, $this->mappedTo($id));
    }

    /** Two categories called the same thing are told apart by their trail. */
    public function test_the_trail_tells_two_identical_names_apart(): void
    {
        $other = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 2]);
        $other->saveTranslations(['ka' => ['name' => 'აქსესუარები', 'slug' => 'accessories']]);

        $nested = Category::create(['parent_id' => $other->id, 'is_active' => true, 'sort_order' => 1]);
        $nested->saveTranslations(['ka' => ['name' => 'სმარტ საათები', 'slug' => 'watch-accessories']]);

        $id = $this->unmapped('საათების აქსესუარები');

        Livewire::test(Index::class)->call('assignByLabel', $id, 'აქსესუარები › სმარტ საათები');

        $this->assertSame($nested->id, $this->mappedTo($id));
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

        $phones = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $phones->saveTranslations(['ka' => ['name' => 'მობილურები', 'slug' => 'phones']]);

        $this->watches = Category::create(['parent_id' => $phones->id, 'is_active' => true, 'sort_order' => 1]);
        $this->watches->saveTranslations(['ka' => ['name' => 'სმარტ საათები', 'slug' => 'smart-watches']]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
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
