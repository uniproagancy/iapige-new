<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\ImageDownloader;
use App\Services\Import\ProductImporter;
use App\Services\Import\ProductPayload;
use App\Services\Import\TaxonomyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codes have to fit the column they go in.
 *
 * The length was measured on the Georgian text and the column was filled with
 * its transliteration, which is longer — შ is sh, ღ is gh, წ is ts. A
 * specification whose value was a whole sentence produced a 90-character code
 * for a 64-character column, MySQL refused the row, the job was retried three
 * times and the import queue jammed behind it.
 *
 * MySQL is what enforced this and SQLite is what the tests run on, so the
 * length is asserted here directly rather than waiting for an exception that
 * only one of the two would raise.
 */
class AttributeCodeLengthTest extends TestCase
{
    use RefreshDatabase;

    /** The exact value that stopped the import. */
    public const SENTENCE = 'ჰობოტ ქოველ 3 თვეში გამოგიგზავნით დასუფთავების შესახებ შეხსენებას და რჩევებს';

    public function test_a_sentence_as_a_value_still_fits_the_column(): void
    {
        $value = $this->resolve($this->attribute(), self::SENTENCE);

        $this->assertNotNull($value);
        $this->assertLessThanOrEqual(64, mb_strlen($value->code));
    }

    /**
     * Georgian is where this bites.
     *
     * Sixty of these letters become ninety once transliterated, which is the
     * whole reason the old limit let the column overflow.
     */
    public function test_a_long_georgian_value_fits(): void
    {
        $value = $this->resolve($this->attribute(), str_repeat('შღწჩძ', 20));

        $this->assertLessThanOrEqual(64, mb_strlen($value->code));
    }

    /**
     * Two sentences that open alike stay two values.
     *
     * A plain cut would have given both the same code, and (attribute_id,
     * code) is unique — so the second would have quietly become the first and
     * a product would carry a specification it does not have.
     */
    public function test_values_sharing_a_long_opening_do_not_collapse(): void
    {
        $attribute = $this->attribute();
        $opening = str_repeat('დასუფთავების შესახებ ', 4);

        $first = $this->resolve($attribute, $opening.'პირველი');
        $second = $this->resolve($attribute, $opening.'მეორე');

        $this->assertNotSame($first->code, $second->code);
        $this->assertSame(2, AttributeValue::where('attribute_id', $attribute->id)->count());
    }

    /** A short value is left exactly as it reads. */
    public function test_a_short_value_is_untouched(): void
    {
        $this->assertSame('shavi', $this->resolve($this->attribute(), 'შავი')->code);
    }

    /** The attribute's own code shares the column and the limit. */
    public function test_a_long_specification_name_fits_too(): void
    {
        $attribute = app(TaxonomyResolver::class)->attribute(
            $this->supplier,
            str_repeat('დასუფთავების შესახებ ', 5),
            true,
        );

        $this->assertLessThanOrEqual(64, mb_strlen($attribute->code));
    }

    /** And the whole way through: this product used to take the queue down. */
    public function test_a_product_with_a_sentence_for_a_specification_imports(): void
    {
        $product = app(ProductImporter::class)->import($this->supplier, new ProductPayload(
            externalId: 'ZM-1',
            sku: '6931474700000',
            costPrice: 1000,
            oldCostPrice: null,
            stock: 5,
            brandName: 'Lenovo',
            categoryName: 'Phones',
            translations: ['ka' => ['name' => 'ტელეფონი']],
            specs: [[
                'name' => 'შეხსენება',
                'value' => self::SENTENCE,
                'locale' => 'ka',
                'filterable' => true,
            ]],
        ));

        $this->assertNotNull($product);

        foreach (AttributeValue::pluck('code') as $code) {
            $this->assertLessThanOrEqual(64, mb_strlen($code));
        }
    }

    /** A brand name is a slug on a wider column, held to the same rule. */
    public function test_an_absurd_brand_name_still_produces_a_usable_slug(): void
    {
        $brand = app(TaxonomyResolver::class)->brand(str_repeat('დასუფთავების ', 40));

        $this->assertLessThanOrEqual(255, mb_strlen($brand->slug));
    }

    /* ------------------------------------------------------------------ helpers */

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Language::insert([
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'is_active' => true, 'is_default' => true, 'sort_order' => 1],
        ]);
        Language::flushCache();

        $this->supplier = Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => 'X', 'is_active' => true,
            'priority' => 10, 'markup' => [['up_to' => 5000, 'percent' => 20]],
        ]);

        $category = Category::create(['parent_id' => null, 'is_active' => true, 'sort_order' => 1]);
        $category->saveTranslations(['ka' => ['name' => 'ტელეფონები', 'slug' => 'phones']]);

        $this->instance(ImageDownloader::class, new class extends ImageDownloader
        {
            public function sync(Product $product, array $urls, int $limit = self::MAX_IMAGES): void {}
        });
    }

    protected function attribute(): Attribute
    {
        return app(TaxonomyResolver::class)->attribute($this->supplier, 'ფერი', true);
    }

    protected function resolve(Attribute $attribute, string $value): ?AttributeValue
    {
        return app(TaxonomyResolver::class)->attributeValue($attribute, [
            ['locale' => 'ka', 'value' => $value],
        ]);
    }
}
