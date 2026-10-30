<?php

declare(strict_types=1);

namespace SocialTests;

use App\Models\SocialPost;
use App\Services\Social\ProductSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductSelectorTest extends TestCase
{
    public function test_final_promotion_price_and_genuine_reference_are_used(): void
    {
        $product = $this->product(['price' => 1000, 'old_price' => 1200]);
        $this->promotion($product, ['promo_price' => 600]);
        $snapshot = (new ProductSelector)->snapshot($product->fresh());

        $this->assertSame(600.0, $snapshot['price']);
        $this->assertSame(1200.0, $snapshot['old_price']);
        $this->assertSame(50, $snapshot['discount_percent']);
        $this->assertSame(25, $snapshot['monthly_price']);
        $this->assertSame(24, $snapshot['installment_months']);
        $this->assertSame('GEL', $snapshot['currency']);
        $this->assertSame('https://iapi.invalid/ka/product/product-'.$product->id, $snapshot['url']);
        $this->assertSame('products/fixture.png', $snapshot['image_path']);
        $this->assertArrayNotHasKey('cost_price', $snapshot);
    }

    public function test_live_percentage_promotion_can_supply_the_real_discount(): void
    {
        $product = $this->product(['price' => 1000, 'old_price' => null]);
        $this->promotion($product, ['discount_percent' => 25]);
        $snapshot = (new ProductSelector)->snapshot($product->fresh());

        $this->assertSame(750.0, $snapshot['price']);
        $this->assertSame(1000.0, $snapshot['old_price']);
        $this->assertSame(25, $snapshot['discount_percent']);
    }

    public function test_fake_discounts_and_final_prices_below_500_are_rejected(): void
    {
        $selector = new ProductSelector;
        $this->assertNull($selector->snapshot($this->product(['price' => 800, 'old_price' => 800])));
        $this->assertNull($selector->snapshot($this->product(['price' => 800, 'old_price' => 700])));
        $this->assertNull($selector->snapshot($this->product(['price' => 499, 'old_price' => 900])));
        $promoBelowLimit = $this->product(['price' => 800]);
        $this->promotion($promoBelowLimit, ['promo_price' => 499]);
        $this->assertNull($selector->snapshot($promoBelowLimit->fresh()));

        $boundary = $this->product(['price' => 500, 'old_price' => 600]);
        $this->assertSame(500.0, $selector->snapshot($boundary)['price']);
    }

    public function test_unavailable_unpublished_and_preorder_products_are_rejected(): void
    {
        $selector = new ProductSelector;
        foreach ([
            ['stock' => 0], ['stock' => -1], ['status' => 'draft'], ['is_preorder' => true],
            ['published_at' => CarbonImmutable::now()->addDay()], ['category_id' => null],
        ] as $attributes) {
            $this->assertNull($selector->snapshot($this->product($attributes)));
        }
    }

    public function test_expired_promotions_do_not_create_an_invented_discount(): void
    {
        $product = $this->product(['price' => 1000, 'old_price' => null]);
        $this->promotion($product, ['promo_price' => 700], ['ends_at' => CarbonImmutable::now()->subDay()]);

        $this->assertNull((new ProductSelector)->snapshot($product->fresh()));
    }

    public function test_a_small_real_discount_does_not_invent_a_one_percent_badge(): void
    {
        $snapshot = (new ProductSelector)->snapshot($this->product(['price' => 999, 'old_price' => 1000]));

        $this->assertSame(999.0, $snapshot['price']);
        $this->assertSame(1000.0, $snapshot['old_price']);
        $this->assertSame(0, $snapshot['discount_percent']);
    }

    public function test_fractional_discount_matches_the_store_and_artwork_rounding(): void
    {
        $snapshot = (new ProductSelector)->snapshot($this->product(['price' => 999, 'old_price' => 1199]));

        $this->assertSame(17, $snapshot['discount_percent']);
        $this->assertGreaterThan(16, (1 - 999 / 1199) * 100);
        $this->assertLessThan(17, (1 - 999 / 1199) * 100);
    }

    public function test_only_existing_local_raster_photos_can_be_selected(): void
    {
        $selector = new ProductSelector;
        Storage::disk('public')->put('products/fake.png', '<html>not an image</html>');
        foreach ([
            null, 'https://external.invalid/photo.png', '//external.invalid/photo.png', '../outside.png',
            'products/../fixture.png', 'products/missing.png', 'products/fake.png', 'C:/outside.png',
            'products/fixture.png?token=secret', 'products/logo.svg',
        ] as $image) {
            $this->assertNull($selector->snapshot($this->product([], $image)));
        }
        $this->assertNotNull($selector->localImagePath('products/fixture.png'));
        $product = $this->product([], 'https://external.invalid/remote.png');
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'products/fixture.png', 'sort_order' => 1]);
        $this->assertSame('products/fixture.png', $selector->snapshot($product)['image_path']);
        config(['filesystems.disks.public.driver' => 's3']);
        $this->assertNull($selector->localImagePath('products/fixture.png'));
    }

    public function test_categories_are_varied_before_repeating_and_products_are_unique(): void
    {
        foreach ([1, 1, 1, 1, 2, 2, 3, 4, 5] as $category) {
            $this->product(['category_id' => $category]);
        }
        $selected = (new ProductSelector)->select('103447072710661', CarbonImmutable::now());

        $this->assertCount(5, $selected);
        $this->assertCount(5, array_unique(array_column($selected, 'category_id')));
        $this->assertCount(5, array_unique(array_column($selected, 'product_id')));
    }

    public function test_recent_publications_unknown_attempts_and_any_same_day_record_reserve_products(): void
    {
        $recent = $this->product();
        $old = $this->product();
        $unknown = $this->product();
        $todaySkipped = $this->product();
        $otherPage = $this->product();
        $this->post($recent, ['published_at' => CarbonImmutable::now()->subDays(6)->utc()]);
        $this->post($old, ['scheduled_for' => CarbonImmutable::now()->subDays(8)->utc(), 'scheduled_date' => '2026-10-21', 'published_at' => CarbonImmutable::now()->subDays(8)->utc()]);
        $this->post($unknown, ['scheduled_for' => CarbonImmutable::parse('2026-10-28 09:00:00', 'UTC'), 'status' => SocialPost::STATUS_UNKNOWN]);
        $this->post($todaySkipped, ['scheduled_for' => CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'), 'scheduled_date' => '2026-10-29', 'status' => SocialPost::STATUS_SKIPPED]);
        $this->post($otherPage, ['page_id' => '999', 'published_at' => CarbonImmutable::now()->utc()]);
        $ids = array_column((new ProductSelector)->select('103447072710661', CarbonImmutable::now()), 'product_id');

        $this->assertEqualsCanonicalizing([$old->id, $otherPage->id], $ids);
    }

    public function test_a_product_becomes_eligible_after_the_full_seven_day_cooldown(): void
    {
        $product = $this->product();
        $at = CarbonImmutable::now();
        $this->post($product, [
            'scheduled_for' => $at->subDays(7)->utc(),
            'scheduled_date' => $at->subDays(7)->toDateString(),
            'published_at' => $at->subDays(7)->utc(),
        ]);
        $selector = new ProductSelector;

        $this->assertSame([$product->id], array_column($selector->select('103447072710661', $at), 'product_id'));
        $this->assertSame([], $selector->select('103447072710661', $at->subSecond()));
    }
}
