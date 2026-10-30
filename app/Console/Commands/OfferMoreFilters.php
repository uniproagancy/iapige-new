<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Services\Import\TaxonomyResolver;
use App\Support\Catalog;
use App\Support\Measure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 | Shelves with nothing to filter by are not shelves the shop knows nothing
 | about: a cable says how long it is, a kettle says what its body is made of,
 | a screwdriver says which kind of screwdriver it is. The line is written on
 | the product page and never offered as a filter, so the whole shelf is one
 | long list.
 |
 | This looks for the specifications that would make a filter and says so. A
 | specification earns it on a shelf by answering for enough of the shelf, and
 | by having few enough answers -- each given by more than one product -- to be
 | a choice rather than a list. The same reading the sidebar makes is used
 | here, so "1.2 მ" and "120 სმ" count as the one answer they are.
 |
 | Offering them is two steps: this says which filters to offer and on which
 | shelves, and `products:refile` then files the products under them.
 */
class OfferMoreFilters extends Command
{
    /* A shelf smaller than this has too little to say for a filter to be
       judged on it either way. */
    public const MIN_SHELF = 60;

    /* How much of a shelf a specification has to answer for. Well above the
       sidebar's own threshold, because a filter turned on here is turned on
       for good and should not be a borderline one. */
    public const COVERAGE = 0.25;

    /* A sidebar is read top to bottom, so a shelf is given its best few
       rather than everything that would pass. */
    public const PER_SHELF = 8;

    protected $signature = 'filters:offer
        {--apply : offer them, which only reporting does not}
        {--coverage= : how much of a shelf a specification must answer for}
        {--per-shelf= : how many filters one shelf may gain}
        {--show=40 : how many shelves to print}';

    protected $description = 'Offer the specifications that would make filters on shelves that have none';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $locale = Language::defaultCode();
        $apply = (bool) $this->option('apply');
        $coverage = (float) ($this->option('coverage') ?: self::COVERAGE);

        $names = DB::table('attribute_translations')->where('locale', $locale)
            ->pluck('name', 'attribute_id');

        $stocked = Catalog::stocked();
        $shown = (int) $this->option('show');
        $printed = 0;

        /** @var array<int, array<int, int>> attribute id => category ids */
        $earned = [];

        foreach (Category::active()->withTranslation()->get() as $shelf) {
            if (($stocked[$shelf->id] ?? 0) < self::MIN_SHELF) {
                continue;
            }

            $found = $this->worthOffering($shelf, $coverage, $locale)
                ->take((int) ($this->option('per-shelf') ?: self::PER_SHELF));

            if ($found->isEmpty()) {
                continue;
            }

            if ($printed < $shown) {
                $this->line('');
                $this->line('  '.$shelf->name.'  ('.$stocked[$shelf->id].')');
                $printed++;

                foreach ($found as $one) {
                    $this->line('    '.str_pad(mb_substr((string) ($names[$one['attribute']] ?? '?'), 0, 28), 30)
                        .str_pad($one['products'].' of '.$one['total'], 14)
                        .$one['answers']->keys()->take(6)->implode(' | '));
                }
            }

            foreach ($found as $one) {
                $earned[$one['attribute']][] = $shelf->id;
            }
        }

        $this->line('');

        if (! $earned) {
            $this->line('Every specification worth a filter is already offered as one.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->line(count($earned).' specifications would be offered as filters (pass --apply to do it)');
            $this->line('then run products:refile --apply to file the products under them');

            return self::SUCCESS;
        }

        foreach (array_keys($earned) as $id) {
            $this->offer($id);
        }

        $this->line(count($earned).' specifications are now offered as filters');
        $this->line('run products:refile --apply to file the products under them');

        return self::SUCCESS;
    }

    /**
     * The specifications this shelf's products answer well enough and few
     * enough ways for the answer to be a choice.
     *
     * @return Collection<int, array{attribute: int, products: int, total: int, answers: Collection<string, int>}>
     */
    protected function worthOffering(Category $shelf, float $coverage, string $locale): Collection
    {
        $ids = Product::listable()->whereIn('category_id', $shelf->descendantAndSelfIds());
        $total = $ids->count();

        if ($total < self::MIN_SHELF) {
            return collect();
        }

        $specs = DB::table('product_specs as s')
            ->join('attributes as a', 'a.id', '=', 's.attribute_id')
            ->join('product_spec_translations as t', function ($join) use ($locale) {
                $join->on('t.product_spec_id', '=', 's.id')->where('t.locale', '=', $locale);
            })
            ->where('a.is_filterable', false)
            ->where('a.is_variant', false)
            ->whereNotIn('a.code', Catalog::RESERVED_FILTERS)
            ->whereIn('s.product_id', $ids->select('products.id')->toBase())
            ->selectRaw('s.attribute_id, s.product_id, t.value')
            ->get()
            ->groupBy('attribute_id');

        return $specs
            ->map(fn (Collection $rows, $attribute) => [
                'attribute' => (int) $attribute,
                'products' => $rows->pluck('product_id')->unique()->count(),
                'total' => $total,
                'answers' => $this->answers($rows),
            ])
            ->filter(fn (array $one) => $coverage <= $one['products'] / $one['total'])
            /* The sidebar's own reading of what is a choice, so nothing is
               turned on here that the shelf would then refuse to print. */
            ->filter(fn (array $one) => $one['answers']->count() > 1
                && $one['answers']->count() <= Catalog::MAX_ANSWERS)
            ->sortByDesc('products')
            ->values();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<string, int>
     */
    protected function answers(Collection $rows): Collection
    {
        return $rows
            ->pluck('value')
            ->map(fn ($said) => ($reading = Measure::read((string) $said))
                ? Measure::canonical($reading)
                : trim((string) $said))
            /* Exactly what the sidebar would print: a line that answers
               nothing, and a line too long to stand in a box, are neither of
               them answers it could offer. */
            ->filter(fn (string $answer) => TaxonomyResolver::valueCode($answer) !== ''
                && mb_strlen($answer) <= Catalog::MAX_OPTION_LENGTH)
            ->countBy()
            ->filter(fn (int $given) => $given >= Catalog::MIN_PER_ANSWER)
            ->sortDesc();
    }

    protected function offer(int $id): void
    {
        $attribute = Attribute::find($id);

        if (! $attribute) {
            return;
        }

        /* Only the offering. The shelves are left alone: a shelf whose
           products say nothing about a specification does not print it
           anyway, and listing the attribute under the shelves that earned it
           is how colour came to be offered on one shelf out of fifty. */
        $attribute->update(['is_filterable' => true]);
    }
}
