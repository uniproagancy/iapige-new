<?php

namespace Tests\Feature;

use App\Livewire\Admin\Products\Index;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "ready to publish" pile.
 *
 * The filter and the publish button answer the same question, and the whole
 * reason the rule is a scope is that they must never answer it differently —
 * a chip counting ten products the button then refuses is worse than no chip.
 */
class ReadyToPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();
    }

    public function test_only_a_complete_draft_counts_as_ready(): void
    {
        $this->product('ready');
        $this->product('no-category', category: false);
        $this->product('no-price', price: 0);
        $this->product('no-name', name: '');
        $this->product('already-live', status: Product::STATUS_ACTIVE);

        $ready = Product::readyToPublish()->where('status', Product::STATUS_DRAFT)->pluck('sku');

        $this->assertSame(['SKU-ready'], $ready->all());
    }

    /** The chip's count and the rows it then shows have to be the same set. */
    public function test_the_filter_shows_exactly_what_the_chip_counts(): void
    {
        $this->product('ready-1');
        $this->product('ready-2');
        $this->product('broken', price: 0);

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(Index::class)
            ->set('issue', 'ready')
            ->assertSee('SKU-ready-1')
            ->assertSee('SKU-ready-2')
            ->assertDontSee('SKU-broken')
            ->assertViewHas('issues', fn ($issues) => $issues['ready'] === 2);
    }

    /** An archived product is not "waiting", but publishing it is still allowed. */
    public function test_archived_is_not_listed_but_can_still_be_published(): void
    {
        $archived = $this->product('retired', status: Product::STATUS_ARCHIVED);

        $this->assertSame(0, Product::readyToPublish()->where('status', Product::STATUS_DRAFT)->count());

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(Index::class)
            ->set('selected', [$archived->id])
            ->call('bulkPublish');

        $this->assertSame(Product::STATUS_ACTIVE, $archived->fresh()->status);
    }

    public function test_bulk_publish_skips_the_unready_and_stamps_published_at(): void
    {
        $good = $this->product('good');
        $bad = $this->product('bad', category: false);

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(Index::class)
            ->set('selected', [$good->id, $bad->id])
            ->call('bulkPublish');

        $this->assertSame(Product::STATUS_ACTIVE, $good->fresh()->status);
        $this->assertNotNull($good->fresh()->published_at);
        $this->assertSame(Product::STATUS_DRAFT, $bad->fresh()->status);
    }

    /** A date already set is the real first publication and must survive. */
    public function test_bulk_publish_keeps_an_existing_published_at(): void
    {
        $product = $this->product('relisted', status: Product::STATUS_ARCHIVED);
        $product->update(['published_at' => '2026-01-01 10:00:00']);

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(Index::class)
            ->set('selected', [$product->id])
            ->call('bulkPublish');

        $this->assertSame('2026-01-01 10:00:00', $product->fresh()->published_at->toDateTimeString());
    }

    protected function product(
        string $sku,
        bool $category = true,
        float $price = 100,
        string $name = 'ლეპტოპი',
        string $status = Product::STATUS_DRAFT,
    ): Product {
        $product = Product::create([
            'sku' => 'SKU-'.$sku,
            'category_id' => $category ? Category::create(['is_active' => true, 'sort_order' => 1])->id : null,
            'price' => $price,
            'stock' => 1,
            'status' => $status,
        ]);

        if ($name !== '') {
            $product->saveTranslations(['ka' => ['name' => $name, 'slug' => $sku]]);
        }

        return $product;
    }
}
