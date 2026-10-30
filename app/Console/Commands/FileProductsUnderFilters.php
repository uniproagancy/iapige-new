<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Language;
use App\Models\ProductSpec;
use App\Services\Import\TaxonomyResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 | A product can say what colour it is in its specifications and still not be
 | filed under the colour filter: the line was written, the filing was not.
 | Then a customer filtering by white is not shown a white one that says so on
 | its own page.
 |
 | This reads those lines and files the product under the answer they give,
 | using the same reading the import itself uses -- so a line saying "N/A" or
 | a dash is left alone, as it always was.
 |
 | Only the filters a customer is offered and the choices shown on a product
 | page: filing anything else would make thousands of answers nobody asks for.
 */
class FileProductsUnderFilters extends Command
{
    public const CHUNK = 500;

    protected $signature = 'products:refile
        {--apply : file them, which only reporting does not}
        {--show=15 : how many examples to print}';

    protected $description = 'File products under the filters their own specifications already answer';

    public function handle(TaxonomyResolver $taxonomy): int
    {
        DB::connection()->disableQueryLog();

        $locale = Language::defaultCode();

        $attributes = Attribute::query()
            ->where(fn ($q) => $q->where('is_filterable', true)->orWhere('is_variant', true))
            ->get()
            ->keyBy('id');

        if ($attributes->isEmpty()) {
            $this->error('No filter is offered to anybody.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $shown = (int) $this->option('show');
        $filed = $unreadable = $printed = 0;

        ProductSpec::query()
            ->whereIn('attribute_id', $attributes->keys())
            ->join('product_spec_translations as t', function ($join) use ($locale) {
                $join->on('t.product_spec_id', '=', 'product_specs.id')
                    ->where('t.locale', '=', $locale);
            })
            /* Nothing to file where the product is already filed. */
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('attribute_value_product as avp')
                    ->join('attribute_values as v', 'v.id', '=', 'avp.attribute_value_id')
                    ->whereColumn('avp.product_id', 'product_specs.product_id')
                    ->whereColumn('v.attribute_id', 'product_specs.attribute_id');
            })
            ->select(['product_specs.id', 'product_specs.product_id', 'product_specs.attribute_id', 't.value'])
            ->orderBy('product_specs.id')
            ->chunk(self::CHUNK, function ($specs) use (
                $attributes, $taxonomy, $locale, $apply, $shown, &$filed, &$unreadable, &$printed
            ) {
                foreach ($specs as $spec) {
                    $attribute = $attributes[$spec->attribute_id] ?? null;
                    $said = trim((string) $spec->value);

                    if (! $attribute || $said === '') {
                        $unreadable++;

                        continue;
                    }

                    /* A dash, "N/A" and the like answer nothing. Read first,
                       so that reporting creates no answers of its own. */
                    $code = TaxonomyResolver::valueCode($said);

                    if ($code === '') {
                        $unreadable++;

                        continue;
                    }

                    $value = $apply
                        ? $taxonomy->attributeValue($attribute, [[
                            'locale' => $locale,
                            'value' => $said,
                            'color' => null,
                        ]])
                        : null;

                    if ($apply && ! $value) {
                        $unreadable++;

                        continue;
                    }

                    if ($printed < $shown) {
                        $this->line('  '.str_pad($attribute->code, 26).'#'.str_pad((string) $spec->product_id, 8).$said);
                        $printed++;
                    }

                    $filed++;

                    if ($apply) {
                        DB::table('attribute_value_product')->insertOrIgnore([
                            'product_id' => $spec->product_id,
                            'attribute_value_id' => $value->id,
                        ]);
                    }
                }

                $taxonomy->forget();
            });

        $this->line('');
        $this->line($apply
            ? $filed.' products filed under a filter they already answered'
            : $filed.' would be filed (pass --apply to do it)');

        if ($unreadable) {
            $this->line($unreadable.' lines said nothing that could be filed');
        }

        return self::SUCCESS;
    }
}
