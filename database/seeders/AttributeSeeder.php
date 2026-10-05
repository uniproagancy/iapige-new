<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Our attributes, plus the supplier spec names that map onto them.
 * Both language variants are listed, because the importer asks the source
 * in every language we keep and each answer lands as its own row.
 */
class AttributeSeeder extends Seeder
{
    /** code => [filterable, variant, ka, en, [supplier spec names…]] */
    protected array $attributes = [
        'brand'      => [false, false, 'ბრენდი', 'Brand', ['Brand', 'ბრენდი']],
        'color'      => [true,  true,  'ფერი', 'Colour', ['Color', 'ფერი']],
        'screen'     => [true,  false, 'ეკრანი', 'Screen', ['Screen size', 'ეკრანის ზომა', 'ეკრანის ტიპი', 'ეკრანის დაფა']],
        'os'         => [true,  false, 'ოპერაციული სისტემა', 'Operating system', ['System', 'System Version', 'სისტემა', 'სისტემის ვერსია']],
        'cpu'        => [false, false, 'პროცესორი', 'Processor', ['Built-in processor', 'ჩაშენებული პროცესორი']],
        'ram'        => [true,  false, 'ოპერატიული მეხსიერება', 'RAM', ['ოპერატიული მეხსიერება']],
        'storage'    => [true,  true,  'მეხსიერება', 'Storage', ['მეხსიერება', 'მყარი მეხსიერება', 'მეხსიერების სტანდარტი', 'Micro SD Slot']],
        'camera'     => [false, false, 'კამერა', 'Camera', ['Additional Camera', 'Front camera', 'ძირითადი კამერა', 'Video resolution of the main camera', 'Front camera video resolution']],
        'battery'    => [false, false, 'ბატარეა', 'Battery', ['Element type', 'ელემენტი', 'ტევადობა']],
        'charging'   => [false, false, 'დამუხტვა', 'Charging', ['Charging Interface', 'Charging Speed', 'Wireless Charging', 'Wireless Charging Speed', 'დამუხტვის ტიპი']],
        'network'    => [true,  false, 'ქსელი', 'Network', ['5G', 'E-SIM', 'SIM ბარათი']],
        'sound'      => [false, false, 'ხმა', 'Sound', ['Stereo Speaker', 'ხმა']],
        'protection' => [false, false, 'დაცვა', 'Protection', ['IP დაცვა', 'დაცვა']],
        'weight'     => [false, false, 'წონა', 'Weight', ['Weight', 'წონა']],
        'size'       => [false, false, 'ზომები', 'Dimensions', ['განზომილების სიმაღლე', 'განზომილების ზომები', 'ზომები']],
    ];

    public function run(): void
    {
        $supplier = Supplier::where('code', 'zoommer')->first();
        $i = 0;

        foreach ($this->attributes as $code => [$filterable, $variant, $ka, $en, $externals]) {
            $attribute = Attribute::updateOrCreate(['code' => $code], [
                'type'          => $code === 'color' ? 'color' : 'select',
                'is_filterable' => $filterable,
                'is_variant'    => $variant,
                'sort_order'    => ++$i,
            ]);

            $attribute->saveTranslations([
                'ka' => ['name' => $ka],
                'en' => ['name' => $en],
            ]);

            if (! $supplier) {
                continue;
            }

            // map both language variants of the supplier's spec name
            foreach ($externals as $external) {
                DB::table('supplier_attribute_map')->updateOrInsert(
                    ['supplier_id' => $supplier->id, 'external_name' => $external],
                    ['attribute_id' => $attribute->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }
}