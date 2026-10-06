<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The category tree is as deep as the data, not three levels.
 *
 * The menu used to eager-load exactly two levels of children and shape them as
 * root → subs → leaves, where a leaf was a flat [name, count, url] triple with
 * nowhere to put children of its own. A shop built three deep fitted that
 * exactly, and the fourth level that already exists in this catalogue could not
 * be rendered at all — those categories were reachable only by typing their
 * address.
 */
class CategoryDepthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        // the tree is memoised per process, so one test's would answer the next
        Catalog::flushTree();
    }

    public function test_the_tree_reaches_the_fifth_level(): void
    {
        $this->chain(['L1', 'L2', 'L3', 'L4', 'L5']);

        $names = [];
        $node = Catalog::tree();

        while ($node) {
            $names[] = $node[0]['name'];
            $node = $node[0]['children'] ?? null;
        }

        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $names);
    }

    /** A product at the bottom counts for every category above it. */
    public function test_counts_roll_up_the_whole_chain(): void
    {
        $chain = $this->chain(['L1', 'L2', 'L3', 'L4']);
        $this->product(end($chain));

        $totals = [];
        $node = Catalog::tree();

        while ($node) {
            $totals[] = $node[0]['total'];
            $node = $node[0]['children'] ?? null;
        }

        $this->assertSame([1, 1, 1, 1], $totals);
    }

    /** The sidebar and footer read `subs`; the drawer reads `children`. */
    public function test_subs_stays_an_alias_for_children(): void
    {
        $this->chain(['L1', 'L2', 'L3']);

        $root = Catalog::tree()[0];

        $this->assertSame($root['children'], $root['subs']);
        $this->assertSame('L2', $root['subs'][0]['name']);
    }

    /**
     * One query's worth of tree, whatever its depth.
     *
     * The count closure used to walk past the two eager-loaded levels, which
     * lazy-loaded a query per node from the third level down.
     */
    public function test_the_tree_does_not_cost_a_query_per_node(): void
    {
        $this->chain(['L1', 'L2', 'L3']);

        // the first build of a process also warms the language caches
        Catalog::tree();

        $shallow = $this->queriesToBuildTree();

        $this->chain(['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7']);
        $deep = $this->queriesToBuildTree();

        // the figure itself is whatever the loader needs; what matters is that
        // twice the depth is not twice the queries
        $this->assertSame($shallow, $deep,
            "a three-level tree took {$shallow} queries and a seven-level one {$deep}");
    }

    protected function queriesToBuildTree(): int
    {
        Catalog::flushTree();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Catalog::tree();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    /** A listing shows its own immediate children, at any level. */
    public function test_a_deep_category_lists_its_children(): void
    {
        $chain = $this->chain(['L1', 'L2', 'L3', 'L4']);
        $this->product(end($chain));

        foreach ([0 => 'L2', 1 => 'L3', 2 => 'L4'] as $i => $expected) {
            $listing = Catalog::listing($chain[$i]);

            $this->assertSame(1, $listing['total'], "{$chain[$i]->name} should list the product");
            $this->assertSame([$expected], array_column($listing['subs'], 'name'));
        }

        // the deepest one has no children of its own, only the product
        $deepest = Catalog::listing($chain[3]);
        $this->assertSame([], $deepest['subs']);
        $this->assertCount(4, $deepest['breadcrumbs']);
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * @param  array<int, string>  $names
     * @return array<int, Category>
     */
    protected function chain(array $names): array
    {
        $chain = [];
        $parent = null;

        foreach ($names as $name) {
            $category = Category::create([
                'parent_id' => $parent?->id, 'is_active' => true, 'sort_order' => 1,
            ]);
            $category->saveTranslations(['ka' => ['name' => $name, 'slug' => strtolower($name)]]);

            $chain[] = $parent = $category->fresh();
        }

        return $chain;
    }

    protected function product(Category $category): Product
    {
        $product = Product::create([
            'sku' => 'DEPTH-'.$category->id,
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now(),
        ]);

        $product->saveTranslations(['ka' => ['name' => 'პროდუქტი', 'slug' => 'depth-'.$category->id]]);

        return $product;
    }
}
