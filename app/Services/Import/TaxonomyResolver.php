<?php

namespace App\Services\Import;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Supplier;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps a supplier's names onto our taxonomy.
 *
 * Categories are never guessed: an unknown one is parked in
 * supplier_category_map for a human to map and the product stays
 * uncategorised, because a wrong category is worse than none.
 *
 * Attributes are the opposite: a spec we cannot show is a loss, so an unknown
 * one is created and mapped automatically. It only becomes a *filter* when the
 * source says it is filterable.
 */
class TaxonomyResolver
{
    /** Unmapped-name hits are written in batches of this many. */
    protected const HIT_FLUSH_AT = 50;

    /*
     | Resolved names, for the life of this worker process.
     |
     | A feed repeats the same few hundred category, brand and spec names across
     | every one of its products, and each one cost two queries every time it
     | appeared. Ten thousand products with fifteen specs each is close to half
     | a million lookups for a few hundred distinct answers.
     |
     | The container holds this as a singleton, so the cache spans every job a
     | worker runs; restarting the worker clears it, which is also what picks up
     | a mapping an admin has just made.
     */
    protected array $memo = [];

    /** table => [supplier_id => [name => hits]] waiting to be written */
    protected array $pendingHits = [];

    /** Values that mean "no value" in the feed and must never become options. */
    protected const EMPTY_VALUES = ['-', '–', '—', 'N/A', 'n/a', 'null', 'None'];

    public function category(Supplier $supplier, ?string $name): ?Category
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $key = "cat:{$supplier->id}:{$name}";

        if (array_key_exists($key, $this->memo)) {
            // an unmapped name still counts, but in memory rather than in a query
            if ($this->memo[$key] === null) {
                $this->countHit('supplier_category_map', $supplier, $name);
            }

            return $this->memo[$key];
        }

        $row = DB::table('supplier_category_map')
            ->where('supplier_id', $supplier->id)
            ->where('external_name', $name)
            ->first();

        if ($row?->category_id) {
            return $this->memo[$key] = Category::find($row->category_id);
        }

        $this->park('supplier_category_map', $supplier, $name);

        return $this->memo[$key] = null;
    }

    /** Brands are safe to create: the name is the identity. */
    public function brand(?string $name): ?Brand
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = Slug::make($name) ?: Str::slug($name);

        return $this->memo["brand:{$slug}"] ??= Brand::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true],
        );
    }

    public function attributeValue(Attribute $attribute, array $rows): ?AttributeValue
    {
        $default = Language::defaultCode();

        $raw = trim((string) (collect($rows)->firstWhere('locale', $default)['value']
            ?? collect($rows)->first()['value']
            ?? ''));

        if ($raw === '' || in_array($raw, self::EMPTY_VALUES, true)) {
            return null;
        }

        $code = Slug::make(Str::limit($raw, 60, '')) ?: Str::slug(Str::limit($raw, 60, ''));

        if ($code === '') {
            return null;   // nothing usable as a stable code
        }

        if (isset($this->memo["av:{$attribute->id}:{$code}"])) {
            return $this->memo["av:{$attribute->id}:{$code}"];
        }

        $value = AttributeValue::firstOrCreate(
            ['attribute_id' => $attribute->id, 'code' => $code],
            [
                'sort_order' => 0,
                'color_hex' => collect($rows)->pluck('color')->filter()->first(),
            ],
        );

        if ($value->wasRecentlyCreated) {
            $value->saveTranslations(
                collect($rows)
                    ->filter(fn ($r) => filled($r['value'] ?? null) && ! in_array(trim($r['value']), self::EMPTY_VALUES, true))
                    ->mapWithKeys(fn ($r) => [$r['locale'] => ['label' => trim($r['value'])]])
                    ->all()
            );
        }

        return $this->memo["av:{$attribute->id}:{$code}"] = $value;
    }

    /** A stable code for a spec name, readable for Latin and Georgian alike. */
    protected function attributeCode(string $name): string
    {
        $code = Slug::make($name) ?: Str::slug($name);

        return $code !== '' ? Str::limit($code, 60, '') : 'spec-'.substr(md5($name), 0, 8);
    }

    /** Record an unmapped name so the admin can see what is waiting. */
    protected function park(string $table, Supplier $supplier, string $name): void
    {
        $exists = DB::table($table)
            ->where('supplier_id', $supplier->id)
            ->where('external_name', $name)
            ->exists();

        $exists
            ? DB::table($table)->where('supplier_id', $supplier->id)->where('external_name', $name)
                ->update(['hits' => DB::raw('hits + 1'), 'updated_at' => now()])
            : DB::table($table)->insert([
                'supplier_id' => $supplier->id,
                'external_name' => $name,
                'hits' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function attribute(Supplier $supplier, string $name, bool $filterable = false): ?Attribute
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $key = "attr:{$supplier->id}:{$name}";

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $row = DB::table('supplier_attribute_map')
            ->where('supplier_id', $supplier->id)
            ->where('external_name', $name)
            ->first();

        if ($row?->attribute_id) {
            return $this->memo[$key] = Attribute::find($row->attribute_id);
        }

        $attribute = Attribute::firstOrCreate(
            ['code' => $this->attributeCode($name)],
            [
                'type' => Str::contains(Str::lower($name), ['color', 'ფერი']) ? 'color' : 'select',
                'is_filterable' => $filterable,
                'is_variant' => false,
                'sort_order' => 100,
            ],
        );

        if ($attribute->wasRecentlyCreated) {
            $attribute->saveTranslations([Language::defaultCode() => ['name' => $name]]);
        }

        // remember the mapping, so the same name resolves without a lookup next time
        DB::table('supplier_attribute_map')->updateOrInsert(
            ['supplier_id' => $supplier->id, 'external_name' => $name],
            ['attribute_id' => $attribute->id, 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->memo[$key] = $attribute;
    }

    /* ------------------------------------------------------------------ hit counting */

    /**
     * Counts an unmapped name without a query.
     *
     * `hits` is what orders the admin's mapping queue, so it has to keep
     * rising — but not one UPDATE at a time. They are batched, and a worker
     * killed mid-run loses at most a handful.
     */
    protected function countHit(string $table, Supplier $supplier, string $name): void
    {
        $this->pendingHits[$table][$supplier->id][$name] =
            ($this->pendingHits[$table][$supplier->id][$name] ?? 0) + 1;

        $total = 0;

        foreach ($this->pendingHits as $suppliers) {
            foreach ($suppliers as $names) {
                $total += array_sum($names);
            }
        }

        if ($total >= self::HIT_FLUSH_AT) {
            $this->flushHits();
        }
    }

    public function flushHits(): void
    {
        foreach ($this->pendingHits as $table => $suppliers) {
            foreach ($suppliers as $supplierId => $names) {
                foreach ($names as $name => $hits) {
                    DB::table($table)
                        ->where('supplier_id', $supplierId)
                        ->where('external_name', $name)
                        ->update(['hits' => DB::raw('hits + '.(int) $hits), 'updated_at' => now()]);
                }
            }
        }

        $this->pendingHits = [];
    }
}
