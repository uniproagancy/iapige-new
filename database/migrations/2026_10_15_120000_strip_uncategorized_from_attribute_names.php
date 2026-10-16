<?php

use App\Models\Attribute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected const PREFIX = 'Uncategorized — ';

    public function up(): void
    {
        $prefixed = DB::table('attribute_translations')
            ->where('name', 'like', self::PREFIX.'%')
            ->get(['attribute_id', 'locale', 'name']);

        foreach ($prefixed as $row) {
            $clean = trim(mb_substr($row->name, mb_strlen(self::PREFIX)));

            if ($clean === '') {
                continue;
            }

            $existing = DB::table('attribute_translations')
                ->where('locale', $row->locale)
                ->where('name', $clean)
                ->where('attribute_id', '!=', $row->attribute_id)
                ->value('attribute_id');

            $existing
                ? $this->merge($row->attribute_id, (int) $existing)
                : $this->rename($row->attribute_id, $row->locale, $clean);
        }

        $this->tidyMap();
    }

    protected function tidyMap(): void
    {
        $rows = DB::table('supplier_attribute_map')
            ->where('external_name', 'like', self::PREFIX.'%')
            ->get(['id', 'supplier_id', 'external_name']);

        foreach ($rows as $row) {
            $clean = trim(mb_substr($row->external_name, mb_strlen(self::PREFIX)));

            if ($clean === '') {
                continue;
            }

            $taken = DB::table('supplier_attribute_map')
                ->where('supplier_id', $row->supplier_id)
                ->where('external_name', $clean)
                ->where('id', '!=', $row->id)
                ->exists();

            $taken
                ? DB::table('supplier_attribute_map')->where('id', $row->id)->delete()
                : DB::table('supplier_attribute_map')->where('id', $row->id)->update(['external_name' => $clean]);
        }
    }

    public function down(): void {}

    protected function merge(int $duplicate, int $keep): void
    {
        DB::table('supplier_attribute_map')
            ->where('attribute_id', $duplicate)
            ->update(['attribute_id' => $keep]);

        $alreadyHas = DB::table('product_specs')
            ->where('attribute_id', $keep)
            ->pluck('product_id');

        DB::table('product_specs')
            ->where('attribute_id', $duplicate)
            ->whereIn('product_id', $alreadyHas)
            ->delete();

        DB::table('product_specs')
            ->where('attribute_id', $duplicate)
            ->update(['attribute_id' => $keep]);

        Attribute::whereKey($duplicate)->delete();
    }

    protected function rename(int $attribute, string $locale, string $name): void
    {
        DB::table('attribute_translations')
            ->where('attribute_id', $attribute)
            ->where('locale', $locale)
            ->update(['name' => $name]);

    }
};
