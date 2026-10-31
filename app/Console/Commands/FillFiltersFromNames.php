<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Services\Import\TaxonomyResolver;
use App\Support\Catalog;
use App\Support\Measure;
use App\Support\Palette;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 | The last place an answer can come from. A refrigerator whose supplier sent
 | no specification for its volume is still called "Samsung RB34 340L", and a
 | case with no colour line is still called "... Black". Such a product stands
 | on a shelf whose every other product a filter can reach, and it alone
 | cannot be reached.
 |
 | Only the filters the product's own shelf offers are filled, which is what
 | makes the answer fit the product: a volume is looked for in a refrigerator's
 | name and not in a kettle's when the kettle shelf has no volume filter.
 |
 | What is left alone, because a name cannot be trusted with it:
 |   - a name naming two colours, or two figures of the same quantity
 |   - a length in centimetres or millimetres -- "185 სმ" in a refrigerator's
 |     name may be its height or its width -- though inches are a screen
 |   - memory, where "8/256GB" holds the working memory and the storage at once
 |
 | Only the filing is added. The product page keeps the lines its supplier
 | wrote: the answer was already on the page, in the name.
 */
class FillFiltersFromNames extends Command
{
    /* A shelf smaller than this says too little about what its filters are. */
    public const MIN_SHELF = 20;

    /* How much of a filter's answers must be readings of one quantity before
       it is treated as a measurement. */
    protected const MEASURED = 0.6;

    /* Two readings closer than this are the same answer: 340 ლ and 0.34 m³. */
    protected const SAME = 0.005;

    protected $signature = 'products:fill-from-names
        {--apply : file them, which only reporting does not}
        {--show=30 : how many examples to print}';

    protected $description = 'File products under their shelf\'s filters where only their name gives the answer';

    /** @var array<int, Collection<int, object>> attribute id => its values with readings and how many hold each */
    protected array $answers = [];

    public function handle(TaxonomyResolver $taxonomy): int
    {
        DB::connection()->disableQueryLog();

        app()->setLocale($locale = Language::defaultCode());

        $apply = (bool) $this->option('apply');
        $shown = (int) $this->option('show');
        $printed = 0;
        $filed = [];
        $stocked = Catalog::stocked();

        foreach (Category::active()->withTranslation()->get() as $shelf) {
            if (($stocked[$shelf->id] ?? 0) < self::MIN_SHELF) {
                continue;
            }

            $products = Product::listable()->where('category_id', $shelf->id)->withTranslation()
                ->get(['id', 'variant_group']);

            if ($products->count() < self::MIN_SHELF) {
                continue;
            }

            foreach (Catalog::listing($shelf)['groups'] as $group) {
                if ($group['key'] === 'brand') {
                    continue;
                }

                $attribute = Attribute::where('code', $group['key'])->first();
                $kind = $attribute ? $this->kindOf($attribute, $group) : null;

                if (! $kind) {
                    continue;
                }

                $answered = DB::table('attribute_value_product as avp')
                    ->join('attribute_values as av', 'av.id', '=', 'avp.attribute_value_id')
                    ->where('av.attribute_id', $attribute->id)
                    ->whereIn('avp.product_id', $products->pluck('id'))
                    ->distinct()->pluck('avp.product_id')->flip();

                foreach ($products as $product) {
                    if (isset($answered[$product->id])) {
                        continue;
                    }

                    $label = $kind === 'colour'
                        ? $this->colourFor($attribute, $product)
                        : $this->readingFor($attribute, $kind, (string) $product->name);

                    if ($label === null) {
                        continue;
                    }

                    $filed[$group['name']] = ($filed[$group['name']] ?? 0) + 1;

                    if ($printed < $shown) {
                        $this->line('  '.str_pad(mb_substr($group['name'], 0, 24), 26).str_pad($label, 14).mb_substr((string) $product->name, 0, 60));
                        $printed++;
                    }

                    if (! $apply) {
                        continue;
                    }

                    $value = $this->existing($attribute, $label)
                        ?? $taxonomy->attributeValue($attribute, [['locale' => $locale, 'value' => $label, 'color' => null]]);

                    if ($value) {
                        DB::table('attribute_value_product')->insertOrIgnore([
                            'product_id' => $product->id,
                            'attribute_value_id' => $value->id,
                        ]);
                    }
                }
            }

            $taxonomy->forget();
        }

        $this->line('');

        arsort($filed);

        foreach ($filed as $filter => $count) {
            $this->line('  '.str_pad(mb_substr($filter, 0, 34), 36).$count);
        }

        $this->line('');
        $this->line(($apply ? 'filed ' : 'would file ').array_sum($filed).' products under a filter their name answers'
            .($apply ? '' : ' (pass --apply to do it)'));

        return self::SUCCESS;
    }

    /*
     | What a filter asks for, read off the answers it already offers: a
     | colour, or a reading of one quantity. A filter asking for anything else
     | -- a kind, a material, a yes or a no -- is not something a name says
     | reliably, and is left alone.
     */
    protected function kindOf(Attribute $attribute, array $group): ?string
    {
        if ($attribute->type === Attribute::TYPE_COLOR) {
            return 'colour';
        }

        $readings = collect($group['items'])->map(fn ($item) => Measure::read($item['label']))->filter();

        if ($readings->count() < count($group['items']) * self::MEASURED) {
            return null;
        }

        $dimensions = $readings->pluck('dimension')->unique();

        if ($dimensions->count() !== 1) {
            return null;
        }

        $dimension = (string) $dimensions->first();

        if ($dimension === 'memory') {
            return null;
        }

        /* A length is a screen when the filter speaks in inches, and no one
           side of anything when it speaks in centimetres. */
        if ($dimension === 'length' && ($readings->pluck('unit')->countBy()->sortDesc()->keys()->first() !== 'inch')) {
            return null;
        }

        return $dimension;
    }

    protected function readingFor(Attribute $attribute, string $dimension, string $name): ?string
    {
        $found = collect(Measure::mentioned($name))
            ->filter(fn (array $reading) => $reading['dimension'] === $dimension)
            ->unique(fn (array $reading) => round($reading['low'], 3));

        if ($dimension === 'length') {
            $found = $found->filter(fn (array $reading) => $reading['unit'] === 'inch');
        }

        return $found->count() === 1 ? Measure::canonical($found->first()) : null;
    }

    /*
     | The colour a name says, written the way this product's own family
     | already writes it -- so the picker on the product page does not offer
     | "Black" beside "შავი" -- or, failing that, the way most of the shop does.
     */
    protected function colourFor(Attribute $attribute, Product $product): ?string
    {
        $base = Palette::named((string) $product->name);

        if (! $base) {
            return null;
        }

        $answers = $this->answersOf($attribute)->where('base', $base);

        if ($answers->isEmpty()) {
            return null;
        }

        if ($product->variant_group) {
            $family = DB::table('attribute_value_product as avp')
                ->join('products as p', 'p.id', '=', 'avp.product_id')
                ->where('p.variant_group', $product->variant_group)
                ->whereIn('avp.attribute_value_id', $answers->pluck('id'))
                ->selectRaw('avp.attribute_value_id as id, count(*) as n')
                ->groupBy('avp.attribute_value_id')
                ->orderByDesc('n')
                ->value('id');

            if ($family) {
                return (string) $answers->firstWhere('id', $family)->label;
            }
        }

        return (string) $answers->sortByDesc('held')->first()->label;
    }

    /* An answer already written under this filter that says the same thing. */
    protected function existing(Attribute $attribute, string $label): ?object
    {
        $wanted = Measure::read($label);
        $answers = $this->answersOf($attribute);

        if (! $wanted) {
            return $answers->firstWhere('label', $label);
        }

        return $answers
            ->filter(fn ($answer) => $answer->reading
                && $answer->reading['dimension'] === $wanted['dimension']
                && abs($answer->reading['low'] - $wanted['low']) <= $wanted['low'] * self::SAME
                && $answer->reading['high'] === null)
            ->sortByDesc('held')
            ->first();
    }

    /** @return Collection<int, object> */
    protected function answersOf(Attribute $attribute): Collection
    {
        return $this->answers[$attribute->id] ??= DB::table('attribute_values as av')
            ->join('attribute_value_translations as t', function ($join) {
                $join->on('t.attribute_value_id', '=', 'av.id')->where('t.locale', '=', Language::defaultCode());
            })
            ->leftJoin('attribute_value_product as avp', 'avp.attribute_value_id', '=', 'av.id')
            ->where('av.attribute_id', $attribute->id)
            ->selectRaw('av.id, t.label, count(avp.product_id) as held')
            ->groupBy('av.id', 't.label')
            ->get()
            ->map(function ($answer) {
                $answer->reading = Measure::read($answer->label);
                $answer->base = Palette::baseOf($answer->label);

                return $answer;
            });
    }
}
