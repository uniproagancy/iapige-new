<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Support\Slug;
use Illuminate\Database\Seeder;

/**
 * The design's demo catalogue, in Georgian and English, so the storefront and
 * the Livewire components run on real rows. Safe to re-run: it upserts by
 * category slug, brand slug, attribute code and product SKU.
 */
class DemoCatalogSeeder extends Seeder
{
    /** @var array<string, int> category key → id */
    protected array $categories = [];

    /** @var array<string, int> "attribute.value" → attribute_value id */
    protected array $values = [];

    public function run(): void
    {
        $this->seedCategories();
        $this->seedAttributes();
        $this->seedBrands();
        $this->seedProducts();
        $this->seedFlagshipDetails();
    }

    /* ================================================================== categories */

    protected function seedCategories(): void
    {
        $tree = [
            ['laptops', 'ლეპტოპები და კომპიუტერები', 'Laptops & Computers', true, [
                ['ultrabooks',     'ულტრაბუქები',          'Ultrabooks'],
                ['gaming-laptops', 'გეიმინგ ლეპტოპები',     'Gaming laptops'],
                ['workstations',   'სამუშაო სადგურები',     'Workstations'],
                ['budget-laptops', 'ბიუჯეტური ლეპტოპები',   'Budget laptops'],
            ]],
            ['phones', 'ტელეფონები', 'Phones', true, [
                ['smartphones', 'სმარტფონები',  'Smartphones'],
                ['foldables',   'დასაკეცი',     'Foldables'],
                ['rugged',      'გამძლე',       'Rugged phones'],
            ]],
            ['audio', 'აუდიო', 'Audio', true, [
                ['headphones', 'ყურსასმენები',      'Headphones'],
                ['speakers',   'დინამიკები',        'Speakers'],
                ['studio',     'სტუდიური ტექნიკა',  'Studio gear'],
            ]],
            ['photo', 'ფოტო & ვიდეო', 'Photo & Video', true, [
                ['cameras',      'კამერები',    'Cameras'],
                ['lenses',       'ობიექტივები', 'Lenses'],
                ['photo-extras', 'ფოტო აქსესუარები', 'Photo accessories'],
            ]],
            ['gaming', 'გეიმინგი', 'Gaming', true, [
                ['consoles',    'კონსოლები',  'Consoles'],
                ['peripherals', 'პერიფერია',  'Peripherals'],
                ['chairs',      'სავარძლები', 'Chairs'],
            ]],
            ['smart-home', 'ჭკვიანი სახლი', 'Smart Home', true, [
                ['lighting', 'განათება',     'Lighting'],
                ['security', 'უსაფრთხოება',  'Security'],
                ['climate',  'კლიმატი',      'Climate'],
            ]],
            ['accessories', 'აქსესუარები', 'Accessories', true, [
                ['chargers', 'დამტენები & კაბელები', 'Chargers & cables'],
                ['bags',     'ჩანთები & ქეისები',    'Bags & cases'],
                ['docks',    'დოკები & ჰაბები',      'Docks & hubs'],
                ['watches',  'ჭკვიანი საათები',      'Smartwatches'],
            ]],
        ];

        foreach ($tree as $i => [$key, $ka, $en, $home, $children]) {
            $root = $this->category($key, $ka, $en, null, $i + 1, $home);

            foreach ($children as $j => [$childKey, $childKa, $childEn]) {
                $this->category($childKey, $childKa, $childEn, $root, $j + 1);
            }
        }
    }

    protected function category(string $key, string $ka, string $en, ?Category $parent, int $sort, bool $home = false): Category
    {
        $category = Category::whereTranslation('slug', Slug::make($ka), 'ka')->first() ?? new Category;
        $category->fill([
            'parent_id'    => $parent?->id,
            'image'        => "https://picsum.photos/seed/elio-cat-{$key}/152/152",
            'is_active'    => true,
            'show_on_home' => $home,
            'sort_order'   => $sort,
        ])->save();

        $category->saveTranslations([
            'ka' => ['name' => $ka, 'slug' => Slug::make($ka)],
            'en' => ['name' => $en, 'slug' => Slug::make($en)],
        ]);

        return tap($category, fn ($c) => $this->categories[$key] = $c->id);
    }

    /* ================================================================== attributes */

    protected function seedAttributes(): void
    {
        //       code        type      filter variant  ka                  en            values [code, ka, en, hex]
        $this->attribute('ram', 'select', true, false, 'ოპერატიული', 'RAM', [
            ['6gb', '6GB', '6GB'], ['8gb', '8GB', '8GB'], ['12gb', '12GB', '12GB'],
            ['16gb', '16GB', '16GB'], ['32gb', '32GB', '32GB'], ['64gb', '64GB', '64GB'],
        ]);
        $this->attribute('screen', 'select', true, false, 'ეკრანი', 'Screen', [
            ['13-14', '13—14″', '13—14″'], ['15-16', '15—16″', '15—16″'],
            ['oled', 'OLED', 'OLED'], ['120hz', '120Hz+', '120Hz+'],
        ]);
        $this->attribute('color', 'color', false, true, 'ფერი', 'Colour', [
            ['graphite', 'Graphite', 'Graphite', '#3A3D42'],
            ['silver',   'Silver',   'Silver',   '#D9D5CE'],
            ['midnight', 'Midnight', 'Midnight', '#1E2330'],
        ]);
        $this->attribute('storage', 'select', false, true, 'მეხსიერება', 'Storage', [
            ['512gb', '512GB SSD', '512GB SSD'], ['1tb', '1TB SSD', '1TB SSD'], ['2tb', '2TB SSD', '2TB SSD'],
        ]);

        // free-text specs on the product page
        foreach ([
            ['model', 'მოდელი', 'Model'], ['cpu', 'პროცესორი', 'Processor'], ['gpu', 'ვიდეობარათი', 'Graphics'],
            ['keyboard', 'კლავიატურა', 'Keyboard'], ['ports', 'პორტები', 'Ports'], ['battery', 'ბატარეა', 'Battery'],
            ['weight', 'წონა', 'Weight'], ['warranty', 'გარანტია', 'Warranty'],
        ] as $i => [$code, $ka, $en]) {
            $this->attribute($code, 'text', false, false, $ka, $en, [], 20 + $i);
        }
    }

    protected function attribute(string $code, string $type, bool $filter, bool $variant, string $ka, string $en, array $values, int $sort = 0): void
    {
        $attribute = Attribute::updateOrCreate(['code' => $code], [
            'type' => $type, 'is_filterable' => $filter, 'is_variant' => $variant, 'sort_order' => $sort,
        ]);
        $attribute->saveTranslations(['ka' => ['name' => $ka], 'en' => ['name' => $en]]);

        foreach ($values as $i => $v) {
            $value = AttributeValue::updateOrCreate(
                ['attribute_id' => $attribute->id, 'code' => $v[0]],
                ['color_hex' => $v[3] ?? null, 'sort_order' => $i + 1],
            );
            $value->saveTranslations(['ka' => ['label' => $v[1]], 'en' => ['label' => $v[2]]]);
            $this->values["{$code}.{$v[0]}"] = $value->id;
        }
    }

    /* ================================================================== brands */

    protected function seedBrands(): void
    {
        // logo brands for the home page rail
        $featured = ['Apple', 'Sony', 'Xiaomi', 'HP', 'Acer', 'Canon', 'GoPro', 'Insta360', 'Polaroid', 'JBL',
            'BOYA', 'Razer', 'Xbox', 'Nintendo', 'Steam Deck', 'Nothing', 'UGREEN', 'Havit', 'Meta', 'Google'];

        foreach ($featured as $i => $name) {
            $slug = Slug::make($name);
            Brand::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'logo' => "/img/brands/{$slug}.png",
                'is_active' => true, 'is_featured' => true, 'sort_order' => $i + 1,
            ]);
        }

        // the demo products' own brands
        foreach (['NORDA', 'VELTA', 'MIRO', 'NEXA', 'AVERA', 'ELIO', 'SONARA', 'KIRA', 'TRIPOD',
                  'LUMEE', 'KRAFT', 'SEATO', 'GUARDA', 'KLIMA', 'CASEO'] as $i => $name) {
            Brand::updateOrCreate(['slug' => Slug::make($name)], [
                'name' => $name, 'is_active' => true, 'is_featured' => false, 'sort_order' => 100 + $i,
            ]);
        }
    }

    /* ================================================================== products */

    protected function seedProducts(): void
    {
        $brands = Brand::pluck('id', 'name');

        /*
         * sku, category, brand, [ka name, en name], [ka summary, en summary], price, old,
         * flags (n = new, f = featured deal), stock, [ram, screen]
         */
        $rows = [
            // laptops
            ['NRD-P14-4070', 'gaming-laptops', 'NORDA', ['Pro 14 გეიმინგ ლეპტოპი · RTX 4070', 'Pro 14 Gaming Laptop · RTX 4070'], ['RTX 4070 · 32GB · 1TB'], 4780, 6640, 'f', 4, ['32gb', '120hz']],
            ['NRD-A13',      'ultrabooks',     'NORDA', ['Air 13 ულტრაბუქი', 'Air 13 Ultrabook'], ['16GB · 512GB · 2.8K'], 3240, 3890, 'f', 8, ['16gb', '13-14']],
            ['VLT-S16',      'workstations',   'VELTA', ['Studio 16 სამუშაო სადგური', 'Studio 16 Workstation'], ['64GB · 2TB · 120Hz'], 7490, null, 'n', 5, ['64gb', '15-16']],
            ['NRD-B15',      'budget-laptops', 'NORDA', ['Basic 15 ლეპტოპი', 'Basic 15 Laptop'], ['8GB · 256GB · FHD'], 1490, null, '', 20, ['8gb', '15-16']],
            ['VLT-F14',      'ultrabooks',     'VELTA', ['Flex 14 ტაჩსკრინიანი', 'Flex 14 Touchscreen'], ['16GB · 512GB · OLED'], 3950, 4490, '', 6, ['16gb', 'oled']],
            ['MRO-B14',      'budget-laptops', 'MIRO',  ['Book 14 ბიუჯეტური', 'Book 14'], ['8GB · 256GB · FHD'], 1890, 2190, '', 14, ['8gb', '13-14']],
            ['NXA-R16',      'gaming-laptops', 'NEXA',  ['Raptor 16 გეიმინგ', 'Raptor 16 Gaming'], ['RTX 4080 · 32GB · 1TB'], 6290, 7400, '', 3, ['32gb', '120hz']],
            ['NXA-R15SE',    'gaming-laptops', 'NEXA',  ['Raptor 15 SE', 'Raptor 15 SE'], ['RTX 4060 · 16GB · 512GB'], 4390, 4990, '', 7, ['16gb', '120hz']],
            ['AVR-S13',      'ultrabooks',     'AVERA', ['Slim 13 OLED', 'Slim 13 OLED'], ['16GB · 512GB · OLED'], 4120, null, '', 9, ['16gb', 'oled']],
            ['AVR-S14P',     'ultrabooks',     'AVERA', ['Slim 14 Pro', 'Slim 14 Pro'], ['32GB · 1TB · OLED'], 5240, 5990, '', 4, ['32gb', 'oled']],
            ['VLT-S14R',     'workstations',   'VELTA', ['Studio 14 რენდერი', 'Studio 14 Render'], ['32GB · 1TB · 14″'], 6840, null, '', 2, ['32gb', '13-14']],
            ['VLT-S16M',     'workstations',   'VELTA', ['Studio 16 Max', 'Studio 16 Max'], ['64GB · 4TB · 16″'], 9450, null, '', 2, ['64gb', '15-16']],
            ['MRO-B15S',     'budget-laptops', 'MIRO',  ['Book 15 სტუდენტური', 'Book 15 Student'], ['8GB · 512GB · FHD'], 2190, 2590, '', 11, ['8gb', '15-16']],
            ['MRO-B13L',     'budget-laptops', 'MIRO',  ['Book 13 Lite', 'Book 13 Lite'], ['8GB · 256GB · 13″'], 1690, null, '', 16, ['8gb', '13-14']],
            ['NRD-P16',      'gaming-laptops', 'NORDA', ['Pro 16 გეიმინგ', 'Pro 16 Gaming'], ['RTX 4090 · 64GB · 2TB'], 7980, 8900, '', 2, ['64gb', '120hz']],
            ['NXA-V14',      'workstations',   'NEXA',  ['Vertex 14 სამუშაო', 'Vertex 14 Pro'], ['32GB · 1TB · 14″'], 5590, 6200, '', 5, ['32gb', '13-14']],
            ['AVR-A15',      'ultrabooks',     'AVERA', ['Air 15 ულტრაბუქი', 'Air 15 Ultrabook'], ['16GB · 512GB · 15″'], 3690, null, '', 10, ['16gb', '15-16']],
            ['NRD-A13SE',    'ultrabooks',     'NORDA', ['Air 13 SE', 'Air 13 SE'], ['8GB · 256GB · 13″'], 2740, 3190, '', 12, ['8gb', '13-14']],
            // phones
            ['AVR-L7',   'smartphones', 'AVERA', ['Lumen 7 სმარტფონი', 'Lumen 7 Smartphone'], ['8GB · 256GB · 6.7 ინჩი', '8GB · 256GB · 6.7″'], 2340, 2990, 'f', 13, ['8gb', null]],
            ['AVR-L7P',  'smartphones', 'AVERA', ['Lumen 7 Pro', 'Lumen 7 Pro'], ['12GB · 512GB · 120Hz'], 3190, null, 'n', 9, ['12gb', null]],
            ['MRO-N5',   'smartphones', 'MIRO',  ['Neo 5 სმარტფონი', 'Neo 5 Smartphone'], ['6GB · 128GB · 6.4 ინჩი', '6GB · 128GB · 6.4″'], 1190, 1390, '', 25, ['6gb', null]],
            ['AVR-FX',   'foldables',   'AVERA', ['Fold X დასაკეცი', 'Fold X Foldable'], ['12GB · 512GB'], 5690, null, '', 3, ['12gb', null]],
            ['MRO-R2',   'rugged',      'MIRO',  ['Rugged R2', 'Rugged R2'], ['6GB · 128GB · IP68'], 1450, 1690, '', 8, ['6gb', null]],
            // audio
            ['ELO-S1',   'headphones', 'ELIO',   ['Studio One ყურსასმენი', 'Studio One Headphones'], ['ANC −42dB · 40სთ', 'ANC −42dB · 40h'], 1190, 1400, 'f', 7, [null, null]],
            ['ELO-BM',   'headphones', 'ELIO',   ['Buds Mini ყურსასმენი', 'Buds Mini Earbuds'], ['TWS · ANC · 28სთ', 'TWS · ANC · 28h'], 320, 420, 'f', 24, [null, null]],
            ['SNR-W300', 'speakers',   'SONARA', ['Wave 300 დინამიკი', 'Wave 300 Speaker'], ['60W · IP67 · 24სთ', '60W · IP67 · 24h'], 590, null, '', 12, [null, null]],
            ['SNR-CB51', 'speakers',   'SONARA', ['Cinema Bar 5.1', 'Cinema Bar 5.1'], ['Soundbar · Dolby'], 1890, null, 'n', 5, [null, null]],
            ['ELO-MIC',  'studio',     'ELIO',   ['Mic Pro USB', 'Mic Pro USB'], ['სტუდიური · 24bit', 'Studio · 24-bit'], 440, 520, '', 15, [null, null]],
            // photo & video
            ['KRA-V4K',  'cameras',      'KIRA',   ['Vision 4K კამერა', 'Vision 4K Camera'], ['Mirrorless · 33MP'], 2890, 3450, 'f', 3, [null, null]],
            ['KRA-A5P',  'cameras',      'KIRA',   ['Action 5 Pro', 'Action 5 Pro'], ['5.3K · 60fps'], 1190, 1340, '', 9, [null, null]],
            ['KRA-P35',  'lenses',       'KIRA',   ['Prime 35mm f/1.8', 'Prime 35mm f/1.8'], ['ობიექტივი', 'Lens'], 1490, null, '', 6, [null, null]],
            ['TRP-CT3',  'photo-extras', 'TRIPOD', ['Carbon T3 სამფეხა', 'Carbon T3 Tripod'], ['1.7m · 3.4kg'], 390, 460, '', 11, [null, null]],
            ['LUM-P200', 'photo-extras', 'LUMEE',  ['Panel 200 განათება', 'Panel 200 Light'], ['Bi-color · 200W'], 690, null, 'n', 7, [null, null]],
            // gaming
            ['NXA-CX',   'consoles',    'NEXA',  ['Console X სტაციონარული', 'Console X'], ['1TB SSD · 4K120'], 1990, null, 'n', 6, [null, null]],
            ['NXA-HG',   'consoles',    'NEXA',  ['Handheld Go', 'Handheld Go'], ['512GB · 7 ინჩი OLED', '512GB · 7″ OLED'], 1340, 1590, '', 8, [null, null]],
            ['KRF-M75',  'peripherals', 'KRAFT', ['Mecha 75 კლავიატურა', 'Mecha 75 Keyboard'], ['Hot-swap · RGB'], 340, 440, '', 18, [null, null]],
            ['KRF-GP',   'peripherals', 'KRAFT', ['Glide Pro თაგვი', 'Glide Pro Mouse'], ['26K DPI · 58გრ', '26K DPI · 58g'], 260, 310, '', 22, [null, null]],
            ['STO-RC',   'chairs',      'SEATO', ['Racer სავარძელი', 'Racer Chair'], ['ერგონომიული', 'Ergonomic'], 890, null, '', 5, [null, null]],
            // smart home
            ['LUM-E27',  'lighting', 'LUMEE',  ['Bulb Color E27', 'Bulb Color E27'], ['16M ფერი · Wi-Fi', '16M colours · Wi-Fi'], 45, 59, '', 60, [null, null]],
            ['LUM-S5',   'lighting', 'LUMEE',  ['Strip 5m LED', 'Strip 5m LED'], ['RGBIC · Music sync'], 129, null, 'n', 40, [null, null]],
            ['GRD-C2K',  'security', 'GUARDA', ['Cam 2K გარე', 'Cam 2K Outdoor'], ['IP66 · Night'], 230, 290, '', 14, [null, null]],
            ['GRD-SK',   'security', 'GUARDA', ['Sensor Kit', 'Sensor Kit'], ['კარი · მოძრაობა', 'Door · Motion'], 180, null, '', 20, [null, null]],
            ['KLM-W2',   'climate',  'KLIMA',  ['Thermo W2', 'Thermo W2'], ['ჭკვიანი თერმოსტატი', 'Smart thermostat'], 340, 399, '', 9, [null, null]],
            // accessories
            ['ELO-D11',  'docks',    'ELIO',  ['Dock USB-C 11-in-1', 'Dock USB-C 11-in-1'], ['HDMI · SD · PD100W'], 240, 290, '', 30, [null, null]],
            ['ELO-C65',  'chargers', 'ELIO',  ['Charger 65W GaN', 'Charger 65W GaN'], ['3 პორტი · კომპაქტი', '3 ports · compact'], 120, null, '', 45, [null, null]],
            ['ELO-PB20', 'chargers', 'ELIO',  ['PowerBank 20K', 'PowerBank 20K'], ['20000mAh · 65W'], 180, null, 'n', 35, [null, null]],
            ['CSO-S14',  'bags',     'CASEO', ['Sleeve 14 ჩანთა', 'Sleeve 14 Bag'], ['წყალგამძლე', 'Water-resistant'], 95, 129, '', 25, [null, null]],
            ['ELO-CB2',  'chargers', 'ELIO',  ['Cable USB-C 2m', 'Cable USB-C 2m'], ['240W · ნაქსოვი', '240W · braided'], 35, 45, '', 80, [null, null]],
            ['ELO-WS',   'watches',  'ELIO',  ['Watch Solis ჭკვიანი საათი', 'Watch Solis Smartwatch'], ['AMOLED · GPS · 7 დღე', 'AMOLED · GPS · 7 days'], 890, 1090, 'f', 9, [null, null]],
        ];

        $sales = count($rows) * 10;

        foreach ($rows as [$sku, $cat, $brand, $names, $summary, $price, $old, $flags, $stock, [$ram, $screen]]) {
            $product = Product::withTrashed()->firstOrNew(['sku' => $sku]);
            $product->fill([
                'category_id'  => $this->categories[$cat],
                'brand_id'     => $brands[$brand],
                'price'        => $price,
                'old_price'    => $old,
                'stock'        => $stock,
                'status'       => Product::STATUS_ACTIVE,
                'is_new'       => str_contains($flags, 'n'),
                'is_featured'  => str_contains($flags, 'f'),
                'sales_count'  => $sales -= 10,
                'published_at' => now(),
            ])->save();

            if ($product->trashed()) {
                $product->restore();
            }

            $product->saveTranslations([
                'ka' => ['name' => $names[0], 'slug' => Slug::make($names[0]).'-'.strtolower($sku), 'summary' => $summary[0]],
                'en' => ['name' => $names[1], 'slug' => Slug::make($names[1]).'-'.strtolower($sku), 'summary' => $summary[1] ?? $summary[0]],
            ]);

            $product->attributeValues()->sync(array_values(array_filter([
                $ram ? $this->values["ram.{$ram}"] : null,
                $screen ? $this->values["screen.{$screen}"] : null,
            ])));

            if ($product->images()->doesntExist()) {
                $count = $sku === 'NRD-P14-4070' ? 5 : 4;
                foreach (range(0, $count - 1) as $i) {
                    $product->images()->create([
                        'path'       => 'https://picsum.photos/seed/'.strtolower($sku)."-{$i}/900/700",
                        'sort_order' => $i,
                    ]);
                }
            }
        }
    }

    /* ================================================================== product page demo */

    protected function seedFlagshipDetails(): void
    {
        $product = Product::where('sku', 'NRD-P14-4070')->firstOrFail();
        $product->update(['rating' => 4.7, 'reviews_count' => 128]);

        $product->saveTranslations([
            'ka' => ['description' => 'Pro 14 აერთიანებს RTX 4070-ს და 14-ინჩიან 165Hz ეკრანს კორპუსში, რომელიც სულ 1.6 კგ იწონის. ორმაგი გამათბობელი მილი და ვაპორ-ჩემბერი იკავებს ტემპერატურას მაშინაც, როცა დატვირთვა საათებს გრძელდება.'],
            'en' => ['description' => 'Pro 14 puts an RTX 4070 and a 14-inch 165Hz display into a body that weighs just 1.6 kg. Twin heat pipes and a vapour chamber keep temperatures in check even through hours of load.'],
        ]);

        // colour + storage options shown on the product page
        $product->attributeValues()->syncWithoutDetaching([
            $this->values['color.graphite'], $this->values['color.silver'], $this->values['color.midnight'],
            $this->values['storage.512gb'], $this->values['storage.1tb'], $this->values['storage.2tb'],
        ]);

        //  attribute   key?   ka                               en
        $specs = [
            ['model',    true,  'Pro 14 (2026)',                  'Pro 14 (2026)'],
            ['cpu',      false, 'Ryzen 9 7940HS · 8 ბირთვი',       'Ryzen 9 7940HS · 8 cores'],
            ['gpu',      true,  'GeForce RTX 4070 8GB',           'GeForce RTX 4070 8GB'],
            ['ram',      false, '32GB DDR5 5600MHz',              '32GB DDR5 5600MHz'],
            ['storage',  false, '1TB NVMe Gen4 SSD',              '1TB NVMe Gen4 SSD'],
            ['screen',   true,  '14″ QHD+ 165Hz · 500 nit',        '14″ QHD+ 165Hz · 500 nit'],
            ['keyboard', false, 'ცალკეული RGB განათება',            'Per-key RGB lighting'],
            ['ports',    false, '2× USB-C · 2× USB-A · HDMI 2.1',  '2× USB-C · 2× USB-A · HDMI 2.1'],
            ['battery',  false, '76Wh · 11 საათამდე',              '76Wh · up to 11 hours'],
            ['weight',   true,  '1.6 კგ',                          '1.6 kg'],
            ['warranty', false, '24 თვე ავტორიზებული',              '24 months, authorised'],
        ];

        $attributes = Attribute::pluck('id', 'code');

        foreach ($specs as $i => [$code, $key, $ka, $en]) {
            $spec = ProductSpec::updateOrCreate(
                ['product_id' => $product->id, 'attribute_id' => $attributes[$code]],
                ['is_key' => $key, 'sort_order' => $i + 1],
            );
            $spec->saveTranslations(['ka' => ['value' => $ka], 'en' => ['value' => $en]]);
        }
    }
}
