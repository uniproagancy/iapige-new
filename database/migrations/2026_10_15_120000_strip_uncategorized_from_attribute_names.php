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

        // whichever way each went, the supplier's own name is tidied last
        $this->tidyMap();
    }

    /**
     * The supplier's own name for the attribute, with the prefix taken off.
     *
     * Done row by row and last, because the name is unique per supplier and
     * the clean one is often already there — mapped by hand in the admin
     * before the import ever wrote the prefixed one. Renaming into it is a
     * duplicate key, which is how this migration failed the first time.
     *
     * Where the clean name is taken, the prefixed row is the redundant one:
     * it says the same thing and nobody chose it.
     */
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

    }
};
