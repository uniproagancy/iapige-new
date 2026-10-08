<?php

namespace Tests\Feature;

use App\Livewire\Admin\Products\Form;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Making and editing a product by hand.
 *
 * Each of these pins one thing the form used to get wrong — a duplicate slug
 * that reached the database as a five-hundred, a "was" price below the price,
 * a product published without a category, an upload rule that accepted SVG,
 * a picture whose file outlived its row, and a redirect that never fired.
 */
class AdminProductFormTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    /* ------------------------------------------------------------------ slugs */

    /** A slug already in use is a message, not a database error. */
    public function test_a_duplicate_slug_is_refused(): void
    {
        $this->product('პირველი', 'pirveli');

        $this->form()
            ->set('sku', 'SKU-NEW')
            ->set('price', '500')
            ->set('translations.ka.name', 'მეორე')
            ->set('translations.ka.slug', 'pirveli')
            ->call('save')
            ->assertHasErrors('translations.ka.slug');

        $this->assertSame(1, Product::count());
    }

    /** Its own slug is not a duplicate of itself. */
    public function test_a_product_can_keep_its_own_slug(): void
    {
        $product = $this->product('პირველი', 'pirveli');

        $this->form($product)
            ->set('price', '600')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(600.0, (float) $product->fresh()->price);
    }

    /** Left empty, one is made from the name. */
    public function test_an_empty_slug_is_generated(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '500')
            ->set('translations.ka.name', 'ახალი პროდუქტი')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotEmpty(Product::first()->slug);
    }

    /* ------------------------------------------------------------------ money */

    /**
     * A "was" price below the price is not a discount.
     *
     * It strikes through a smaller number than the one beside it, and
     * discountPercent() declines to work out a percentage — so the card
     * carried a sale tag with nothing to show for it.
     */
    public function test_an_old_price_below_the_price_is_refused(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '1000')
            ->set('old_price', '800')
            ->set('translations.ka.name', 'პროდუქტი')
            ->call('save')
            ->assertHasErrors('old_price');
    }

    public function test_an_old_price_above_the_price_is_accepted(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '800')
            ->set('old_price', '1000')
            ->set('translations.ka.name', 'პროდუქტი')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(20, Product::first()->discountPercent());
    }

    /* ------------------------------------------------------------------ publishing */

    /**
     * The status list was a way round the checks.
     *
     * Publish ran them; choosing "active" and pressing Save did not, so a
     * product with no category went live — visible in listings and
     * unreachable by browsing, which goes through categories.
     */
    public function test_a_product_with_no_category_cannot_be_set_active(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '500')
            ->set('status', Product::STATUS_ACTIVE)
            ->set('translations.ka.name', 'პროდუქტი')
            ->call('save');

        $this->assertSame(0, Product::count());
    }

    public function test_a_complete_product_can_be_set_active(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '500')
            ->set('category_id', $this->category->id)
            ->set('status', Product::STATUS_ACTIVE)
            ->set('translations.ka.name', 'პროდუქტი')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::first();

        $this->assertSame(Product::STATUS_ACTIVE, $product->status);
        $this->assertNotNull($product->published_at);
    }

    /** A draft needs neither, because nobody is shown it. */
    public function test_a_draft_may_be_incomplete(): void
    {
        $this->form()
            ->set('sku', 'SKU-1')
            ->set('price', '0')
            ->set('translations.ka.name', 'ნახევრად შევსებული')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Product::count());
    }

    /* ------------------------------------------------------------------ images */

    /** The image rule accepts SVG, which is a script file. */
    public function test_an_svg_upload_is_refused(): void
    {
        Storage::fake('public');

        $product = $this->product('პროდუქტი', 'produqti');

        Livewire::test(Form::class, ['product' => $product])
            ->set('upload', [UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml')])
            ->call('uploadImages')
            ->assertHasErrors('upload.0');

        $this->assertSame(0, $product->images()->count());
    }

    public function test_a_photograph_is_accepted(): void
    {
        Storage::fake('public');

        $product = $this->product('პროდუქტი', 'produqti');

        Livewire::test(Form::class, ['product' => $product])
            ->set('upload', [UploadedFile::fake()->image('photo.jpg')])
            ->call('uploadImages')
            ->assertHasNoErrors();

        $this->assertSame(1, $product->images()->count());
    }

    /** The row went and the file stayed behind for ever. */
    public function test_deleting_a_picture_removes_its_file(): void
    {
        Storage::fake('public');

        $product = $this->product('პროდუქტი', 'produqti');
        $path = UploadedFile::fake()->image('photo.jpg')->store("products/{$product->id}", 'public');

        $image = ProductImage::create(['product_id' => $product->id, 'path' => $path, 'sort_order' => 0]);

        Storage::disk('public')->assertExists($path);

        Livewire::test(Form::class, ['product' => $product])->call('deleteImage', $image->id);

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, $product->images()->count());
    }

    /** A picture belonging to another product is not this form's to delete. */
    public function test_a_picture_of_another_product_is_left_alone(): void
    {
        Storage::fake('public');

        $mine = $this->product('ჩემი', 'chemi');
        $other = $this->product('სხვისი', 'skhvisi');

        $image = ProductImage::create(['product_id' => $other->id, 'path' => 'products/x.jpg', 'sort_order' => 0]);

        Livewire::test(Form::class, ['product' => $mine])->call('deleteImage', $image->id);

        $this->assertModelExists($image);
    }

    /* ------------------------------------------------------------------ specs */

    /** A spec id from the browser must belong to the product being edited. */
    public function test_a_spec_of_another_product_cannot_be_written(): void
    {
        $mine = $this->product('ჩემი', 'chemi');
        $other = $this->product('სხვისი', 'skhvisi');

        $attribute = Attribute::create(['code' => 'color', 'is_filterable' => true, 'sort_order' => 1]);
        $spec = ProductSpec::create(['product_id' => $other->id, 'attribute_id' => $attribute->id, 'sort_order' => 1]);
        $spec->saveTranslations(['ka' => ['value' => 'შავი']]);

        Livewire::test(Form::class, ['product' => $mine])
            ->set("specs.{$spec->id}", 'თეთრი')
            ->set('price', '500')
            ->call('save');

        $this->assertSame('შავი', $spec->fresh()->value);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $this->category->saveTranslations(['ka' => ['name' => 'ტექნიკა', 'slug' => 'teqnika']]);

        Brand::create(['name' => 'Acme', 'slug' => 'acme', 'is_active' => true]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function form(?Product $product = null)
    {
        return $product
            ? Livewire::test(Form::class, ['product' => $product])
            : Livewire::test(Form::class);
    }

    protected function product(string $name, string $slug): Product
    {
        $product = Product::create([
            'sku' => 'SKU-'.str()->random(6),
            'category_id' => $this->category->id,
            'price' => 1000,
            'stock' => 5,
            'status' => Product::STATUS_DRAFT,
        ]);

        $product->saveTranslations(['ka' => ['name' => $name, 'slug' => $slug]]);

        return $product->fresh();
    }
}
