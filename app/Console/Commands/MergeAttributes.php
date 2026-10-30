<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 | One filter, two records. Each supplier's own word for a specification makes
 | an attribute of its own, so the shop ends up offering "ფერი" twice over --
 | once from a list that called it Color and once from a list that called it
 | ფერი. Whoever translated them gave both the same Georgian name, and that is
 | the signal this follows: same name, same filter.
 |
 | Deliberately literal. Two names match only when they read the same word for
 | word once the suppliers' mark-up litter is gone, so "ხმაურის დონე (დბა)"
 | and "ხმაურის დონე (რეცხვა)" are left apart, because they are two things.
 */
class MergeAttributes extends Command
{
    /* Litter the suppliers' pages left behind: a non-breaking space, a bullet,
       an em space -- written as an entity and then carried into the name. */
    public const LITTER = ['nbsp', 'bull', 'emsp', 'ensp', 'ndash', 'mdash', 'middot', 'amp', 'laquo', 'raquo'];

    protected $signature = 'attributes:merge
        {--apply : merge them, which only reporting does not}
        {--show=25 : how many groups to print}';

    protected $description = 'Join filters that are the same filter written twice';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $locale = Language::defaultCode();

        $attributes = DB::table('attributes')
            ->leftJoin('attribute_translations', function ($join) use ($locale) {
                $join->on('attribute_translations.attribute_id', '=', 'attributes.id')
                    ->where('attribute_translations.locale', '=', $locale);
            })
            ->leftJoin('product_specs', 'product_specs.attribute_id', '=', 'attributes.id')
            ->selectRaw('attributes.id, attributes.code, attributes.is_filterable, attributes.is_variant,
                attribute_translations.name, count(distinct product_specs.product_id) as products')
            ->groupBy('attributes.id', 'attributes.code', 'attributes.is_filterable', 'attributes.is_variant', 'attribute_translations.name')
            ->get();

        $groups = $attributes
            ->groupBy(fn ($row) => self::plain($row->name))
            ->forget('')
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->sortByDesc(fn (Collection $group) => $group->sum('products'));

        if ($groups->isEmpty()) {
            $this->line('Every filter is written only once.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $shown = (int) $this->option('show');
        $printed = $joined = 0;

        $this->line($attributes->count().' filters, '.$groups->count().' of them written more than once');
        $this->line('');

        foreach ($groups as $name => $group) {
            /* The one most products are already filed under keeps its place;
               the others are folded into it. */
            $group = $group->sortByDesc('products')->values();
            $keeper = $group->first();
            $losers = $group->slice(1);

            if ($printed < $shown) {
                $this->line('  '.$name.'  → '.$keeper->code.' ('.$keeper->products.' products)');

                foreach ($losers as $loser) {
                    $this->line('       + '.$loser->code.'  '.$loser->products.' products');
                }

                $printed++;
            }

            $joined += $losers->count();

            if ($apply) {
                $this->fold($keeper, $losers);
            }
        }

        $this->line('');
        $this->line($apply
            ? $joined.' filters folded into another, leaving '.($attributes->count() - $joined)
            : $joined.' filters would be folded in, leaving '.($attributes->count() - $joined).' (pass --apply to do it)');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, object>  $losers
     */
    protected function fold(object $keeper, Collection $losers): void
    {
        foreach ($losers as $loser) {
            DB::transaction(function () use ($keeper, $loser) {
                $this->moveValues((int) $keeper->id, (int) $loser->id);
                $this->moveSpecs((int) $keeper->id, (int) $loser->id);
                $this->moveShelves((int) $keeper->id, (int) $loser->id);

                DB::table('supplier_attribute_map')->where('attribute_id', $loser->id)
                    ->update(['attribute_id' => $keeper->id]);

                DB::table('attribute_translations')->where('attribute_id', $loser->id)->delete();
                DB::table('attributes')->where('id', $loser->id)->delete();
            });

            /* Whatever either of them was allowed to do, the survivor does:
               a filter folded into a variant stays both. */
            Attribute::whereKey($keeper->id)->update([
                'is_filterable' => (bool) ($keeper->is_filterable || $loser->is_filterable),
                'is_variant' => (bool) ($keeper->is_variant || $loser->is_variant),
            ]);

            $keeper->is_filterable = $keeper->is_filterable || $loser->is_filterable;
            $keeper->is_variant = $keeper->is_variant || $loser->is_variant;
        }
    }

    /*
     | Both sides can hold the same answer -- "შავი" under Color and under
     | ფერი -- and a product may be filed under both. The survivor's own value
     | is the one kept, and the other is pointed at it before it goes.
     */
    protected function moveValues(int $keeper, int $loser): void
    {
        $theirs = DB::table('attribute_values')->where('attribute_id', $keeper)->pluck('id', 'code');

        foreach (DB::table('attribute_values')->where('attribute_id', $loser)->get(['id', 'code']) as $value) {
            $same = $theirs[$value->code] ?? null;

            if (! $same) {
                DB::table('attribute_values')->where('id', $value->id)->update(['attribute_id' => $keeper]);

                continue;
            }

            $already = DB::table('attribute_value_product')->where('attribute_value_id', $same)->pluck('product_id');

            DB::table('attribute_value_product')->where('attribute_value_id', $value->id)
                ->whereIn('product_id', $already)->delete();

            DB::table('attribute_value_product')->where('attribute_value_id', $value->id)
                ->update(['attribute_value_id' => $same]);

            DB::table('attribute_value_translations')->where('attribute_value_id', $value->id)->delete();
            DB::table('attribute_values')->where('id', $value->id)->delete();
        }
    }

    /* A product can have a line for both, and only one line may remain. */
    protected function moveSpecs(int $keeper, int $loser): void
    {
        $already = DB::table('product_specs')->where('attribute_id', $keeper)->pluck('product_id');

        DB::table('product_specs')->where('attribute_id', $loser)
            ->whereIn('product_id', $already)->delete();

        DB::table('product_specs')->where('attribute_id', $loser)
            ->update(['attribute_id' => $keeper]);
    }

    /*
     | A filter can be kept to certain shelves. One of the two being offered
     | everywhere means the filter belongs everywhere, so the survivor's own
     | shelves are dropped rather than narrowed.
     */
    protected function moveShelves(int $keeper, int $loser): void
    {
        $open = DB::table('attribute_category')->where('attribute_id', $keeper)->doesntExist()
            || DB::table('attribute_category')->where('attribute_id', $loser)->doesntExist();

        if ($open) {
            DB::table('attribute_category')->whereIn('attribute_id', [$keeper, $loser])->delete();

            return;
        }

        $already = DB::table('attribute_category')->where('attribute_id', $keeper)->pluck('category_id');

        DB::table('attribute_category')->where('attribute_id', $loser)
            ->whereIn('category_id', $already)->delete();

        DB::table('attribute_category')->where('attribute_id', $loser)
            ->update(['attribute_id' => $keeper]);
    }

    public static function plain(?string $name): string
    {
        $name = html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = str_replace(["\u{00a0}", "\u{2022}", "\u{2003}", "\u{2002}"], ' ', $name);
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        $words = array_values(array_filter(
            explode(' ', $name),
            fn (string $word) => $word !== '' && ! in_array($word, self::LITTER, true),
        ));

        return implode(' ', $words);
    }
}
