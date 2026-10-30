<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 | One answer, offered twice. Suppliers write the same thing differently --
 | "4GB" and "4 GB", "1300 W" and "1300W", "50-60 Hz" and "50/60Hz" -- and a
 | customer who picks one of them is shown half the products that answer to it.
 |
 | Two answers are the same when only the spacing and the punctuation differ.
 | Nothing is judged by meaning: "1 TB" and "1024 GB" stay apart, because
 | deciding they are the same is a person's job.
 */
class MergeAttributeValues extends Command
{
    protected $signature = 'attributes:values
        {--apply : join them, which only reporting does not}
        {--show=20 : how many to print}';

    protected $description = 'Join answers that are the same answer spelt differently';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $locale = Language::defaultCode();

        $values = DB::table('attribute_values as v')
            ->join('attribute_value_translations as t', function ($join) use ($locale) {
                $join->on('t.attribute_value_id', '=', 'v.id')
                    ->where('t.locale', '=', $locale);
            })
            ->leftJoin('attribute_value_product as p', 'p.attribute_value_id', '=', 'v.id')
            ->selectRaw('v.id, v.attribute_id, v.color_hex, t.label, count(p.product_id) as products')
            ->groupBy('v.id', 'v.attribute_id', 'v.color_hex', 't.label')
            ->get();

        $families = $values
            ->groupBy(fn ($value) => $value->attribute_id.':'.self::plain($value->label))
            ->reject(fn (Collection $group, string $key) => str_ends_with($key, ':'))
            ->filter(fn (Collection $group) => $group->count() > 1);

        if ($families->isEmpty()) {
            $this->line('Every answer is written only one way.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $shown = (int) $this->option('show');
        $printed = $joined = 0;

        $names = DB::table('attribute_translations')->where('locale', $locale)
            ->pluck('name', 'attribute_id');

        foreach ($families as $group) {
            /* The spelling most products already carry is the one that stays;
               a tie keeps the older. */
            $group = $group->sortByDesc('products')->values();
            $keeper = $group->first();
            $losers = $group->slice(1);

            if ($printed < $shown) {
                $this->line('  '.str_pad(mb_substr((string) ($names[$keeper->attribute_id] ?? '?'), 0, 24), 26)
                    .'"'.$keeper->label.'" ('.$keeper->products.')  <- '
                    .$losers->map(fn ($loser) => '"'.$loser->label.'" ('.$loser->products.')')->implode(', '));

                $printed++;
            }

            $joined += $losers->count();

            if ($apply) {
                $this->fold($keeper, $losers);
            }
        }

        $this->line('');
        $this->line($apply
            ? $joined.' spellings folded into another'
            : $joined.' spellings would be folded in (pass --apply to do it)');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, object>  $losers
     */
    protected function fold(object $keeper, Collection $losers): void
    {
        DB::transaction(function () use ($keeper, $losers) {
            $already = DB::table('attribute_value_product')
                ->where('attribute_value_id', $keeper->id)->pluck('product_id');

            foreach ($losers as $loser) {
                /* A product filed under both spellings keeps the one that
                   stays, rather than being filed twice. */
                DB::table('attribute_value_product')->where('attribute_value_id', $loser->id)
                    ->whereIn('product_id', $already)->delete();

                DB::table('attribute_value_product')->where('attribute_value_id', $loser->id)
                    ->update(['attribute_value_id' => $keeper->id]);

                DB::table('attribute_value_translations')->where('attribute_value_id', $loser->id)->delete();
                DB::table('attribute_values')->where('id', $loser->id)->delete();
            }

            /* A swatch is worth keeping wherever it was written down. */
            if (! $keeper->color_hex) {
                $hex = $losers->pluck('color_hex')->filter()->first();

                if ($hex) {
                    DB::table('attribute_values')->where('id', $keeper->id)->update(['color_hex' => $hex]);
                }
            }
        });
    }

    /* Letters and figures only, so "4GB", "4 GB" and "4-gb" are one answer. */
    public static function plain(?string $label): string
    {
        $label = html_entity_decode((string) $label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $label));
    }
}
