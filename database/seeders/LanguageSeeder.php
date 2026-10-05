<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული', 'short_name' => 'ქარ', 'regional' => 'ka_GE', 'script' => 'Geor', 'flag' => '🇬🇪', 'is_default' => true,  'is_active' => true, 'sort_order' => 1],
            ['code' => 'en', 'name' => 'English',  'native_name' => 'English',  'short_name' => 'ENG', 'regional' => 'en_GB', 'script' => 'Latn', 'flag' => '🇬🇧', 'is_default' => false, 'is_active' => true, 'sort_order' => 2],
        ];

        foreach ($languages as $language) {
            Language::updateOrCreate(['code' => $language['code']], $language);
        }

        Language::flushCache();
    }
}
