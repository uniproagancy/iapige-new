<?php

use App\Models\Attribute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Metromart's specifications all announced that they had no group.
 *
 * The group heading is put in front of an attribute's name to tell two of the
 * same name apart — "ზომა & წონა — სიგანე" is a different thing from a
 * screen's width. But Odoo files everything nobody grouped under
 * "Uncategorized", and on that shop it is most of them, so the catalogue
 * filled with "Uncategorized — ფერი" and "Uncategorized — ტიპი".
 *
 * The importer no longer writes them that way. These are the ones already
 * written, and they cannot simply be renamed: an attribute by the clean name
 * may exist already, from another supplier or from a row that did have a
 * group. Where it does, everything pointing at the prefixed one is moved
 * across and the duplicate removed; where it does not, the name is corrected
 * in place.
 */
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
    }

    /**
     * There is no going back.
     *
     * The prefix said nothing, so putting it back would only restore the
     * confusion — and a merged attribute cannot be split again in any case.
     */
    public function down(): void {}

    /** Everything that pointed at the duplicate now points at the real one. */
    protected function merge(int $duplicate, int $keep): void
    {
        DB::table('supplier_attribute_map')
            ->where('attribute_id', $duplicate)
            ->update(['attribute_id' => $keep]);

        /*
         * A product may carry both, which the unique key on the specs table
         * refuses — so the ones that would collide are dropped rather than
         * moved. They say the same thing as the row that stays.
         */
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

        // its translations and values go with it
        Attribute::whereKey($duplicate)->delete();
    }

    protected function rename(int $attribute, string $locale, string $name): void
    {
        DB::table('attribute_translations')
            ->where('attribute_id', $attribute)
            ->where('locale', $locale)
            ->update(['name' => $name]);

        DB::table('supplier_attribute_map')
            ->where('attribute_id', $attribute)
            ->where('external_name', 'like', self::PREFIX.'%')
            ->update(['external_name' => $name]);
    }
};
