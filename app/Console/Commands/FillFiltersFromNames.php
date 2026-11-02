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

    /* Two readings closer than this are the same answer written in two
       units -- 340 ლ and 340000 მლ -- and no closer: a 249 ლ refrigerator
       is not the 250 ლ one beside it. */
    protected const SAME = 0.0001;

    /*
     | When a shelf asks for one quantity in more than one filter, the figure
     | in a name is the headline one: a refrigerator's litres are its whole
     | volume and not its freezer's, an air conditioner's BTU its cooling, a
     | washing machine's kilograms what it holds and not what it weighs, a
     | charger's watts the most it gives. A shelf with several such filters
     | and none of these among them is left alone -- its name cannot say
     | which of them it means.
     */
    protected const HEADLINE = [
        'volume' => ['mtliani-motsuloba', 'motsuloba'],
        'heat' => ['gagrilebis-simdzlavre-btu'],
        'weight' => ['maksimaluri-chatvirtva', 'maksimaluri-datvirtva'],
        'power' => ['max-vati', 'max-simdzlavre', 'simdzlavre'],
        'length' => ['ekranis-zoma', 'screen'],
    ];

    /*
     | Memory is two questions a name answers at once. "12/256GB", or "8GB,
     | 128GB" in a laptop's list, is the working memory and the storage; the
     | smaller is the first, the larger the second, and nobody makes a
     | telephone with 64GB of working memory. A name giving one figure gives
     | the storage, when it is that large -- Apple names its storage and
     | never its working memory, and "16GB" alone could be either.
     */
    protected const MEMORY = [
        'operatiuli-mekhsiereba' => 'ram',
        'shida-mekhsiereba' => 'storage',
    ];

    protected const MOST_RAM_GB = 64;

    protected const LEAST_STORAGE_GB = 64;

    /*
     | Filters a name is never the answer to. A product's own weight is almost
     | never in its name; the kilograms that are -- "Sencor SBL 131GR", a
     | washing machine's "9 Kg", coffee's "1Kg" -- are a model, a load or the
     | contents.
     */
    protected const NOT_FROM_NAMES = ['tsona'];

    /*
     | A figure is believed only when it is the size the shelf's own answers
     | are, this far beyond the spread of the middle of them at most. A drill
     | sold as "198V" beside drills of 12 to 40 volts is a slogan, not a
     | voltage.
     */
    protected const PLAUSIBLE = 1.5;

    /* How many readings a filter must already hold for its spread to mean
       anything: two are enough to say what size the shelf's things are,
       and a washing machine shelf often offers no more than 6, 7 and 8 კგ. */
    protected const SPREAD = 2;

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
                ->with('brand:id,name')
                ->get(['id', 'variant_group', 'brand_id']);

            if ($products->count() < self::MIN_SHELF) {
                continue;
            }

            foreach ($this->fillable(Catalog::listing($shelf)['groups']) as [$group, $attribute, $kind, $range]) {

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
                        : ($kind === 'memory'
                            ? $this->memoryFor($attribute, $range, (string) $product->name)
                            : $this->readingFor($kind, $range, (string) $product->name));

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
        if (in_array($attribute->code, self::NOT_FROM_NAMES, true)) {
            return null;
        }

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

        if ($dimension === 'memory' && ! isset(self::MEMORY[$attribute->code])) {
            return null;
        }

        /* A length is a screen when the filter speaks in inches, and no one
           side of anything when it speaks in centimetres. */
        if ($dimension === 'length' && ($readings->pluck('unit')->countBy()->sortDesc()->keys()->first() !== 'inch')) {
            return null;
        }

        return $dimension;
    }

    /**
     * The filters on a shelf a name can answer, each with what it asks for and
     * the sizes its answers come in.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array{0: array<string, mixed>, 1: Attribute, 2: string, 3: ?array{0: float, 1: float}}>
     */
    protected function fillable(array $groups): array
    {
        $found = [];

        foreach ($groups as $group) {
            if ($group['key'] === 'brand') {
                continue;
            }

            $attribute = Attribute::where('code', $group['key'])->first();
            $kind = $attribute ? $this->kindOf($attribute, $group) : null;

            if ($kind) {
                $found[] = [$group, $attribute, $kind, $kind === 'colour' ? null : $this->rangeOf($group)];
            }
        }

        return collect($found)
            ->groupBy(fn (array $one) => $one[2])
            ->flatMap(function (Collection $same, string $kind) {
                /* A colour is a colour, and the two memories are told apart by
                   their size rather than by a headline. */
                if ($same->count() === 1 || $kind === 'colour' || $kind === 'memory') {
                    return $same->all();
                }

                foreach (self::HEADLINE[$kind] ?? [] as $code) {
                    $headline = $same->first(fn (array $one) => $one[1]->code === $code);

                    if ($headline) {
                        return [$headline];
                    }
                }

                return [];
            })
            /* A filter holding too few readings to show their spread is not
               one a name's figure can be checked against. */
            ->filter(fn (array $one) => $one[2] === 'colour' || $one[3] !== null)
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{0: float, 1: float}|null
     */
    protected function rangeOf(array $group): ?array
    {
        $sizes = collect($group['items'])
            ->map(fn (array $item) => Measure::read($item['label']))
            ->filter()
            ->flatMap(fn (array $reading) => array_values(array_filter([$reading['low'], $reading['high']], fn ($size) => $size !== null)))
            ->sort()->values();

        if ($sizes->count() < self::SPREAD) {
            return null;
        }

        /* The low end rounded down and the high end up, so a shelf of two
           sizes spans both of them rather than the smaller twice. */
        $last = $sizes->count() - 1;
        $low = (float) $sizes[(int) floor($last * 0.1)];
        $high = (float) $sizes[(int) ceil($last * 0.9)];

        return [$low / self::PLAUSIBLE, $high * self::PLAUSIBLE];
    }

    /**
     * @param  array{0: float, 1: float}|null  $range
     */
    protected function readingFor(string $dimension, ?array $range, string $name): ?string
    {
        $found = collect(Measure::mentioned($name))
            ->filter(fn (array $reading) => $reading['dimension'] === $dimension)
            ->unique(fn (array $reading) => round($reading['low'], 3).'-'.round((float) $reading['high'], 3));

        if ($dimension === 'length') {
            $found = $found->filter(fn (array $reading) => $reading['unit'] === 'inch');
        }

        if ($found->count() !== 1) {
            return null;
        }

        $reading = $found->first();

        if ($range && ($reading['low'] < $range[0] || ($reading['high'] ?? $reading['low']) > $range[1])) {
            return null;
        }

        return Measure::canonical($reading);
    }

    /**
     * @param  array{0: float, 1: float}|null  $range
     */
    protected function memoryFor(Attribute $attribute, ?array $range, string $name): ?string
    {
        $role = self::MEMORY[$attribute->code];
        $gigabyte = Measure::factor('memory', 'gb');
        $said = mb_strtolower($name);
        $found = [];

        /* "12/256GB": the unit is written once, after the second figure. */
        if (preg_match('/(?<![\d.])(\d+)\s*(?:gb)?\s*[\/+]\s*(\d+)\s*(gb|tb)(?![\p{L}\d])/u', $said, $pair)) {
            $found[] = (float) $pair[1] * $gigabyte;
            $found[] = (float) $pair[2] * Measure::factor('memory', $pair[3]);
            $said = str_replace($pair[0], ' ', $said);
        }

        foreach (Measure::mentioned($said) as $reading) {
            if ($reading['dimension'] === 'memory') {
                $found[] = $reading['low'];
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        $size = match (true) {
            count($found) === 1 => $role === 'storage' && $found[0] >= self::LEAST_STORAGE_GB * $gigabyte ? $found[0] : null,
            count($found) === 2 && $found[0] <= self::MOST_RAM_GB * $gigabyte && $found[1] >= 4 * $found[0] => $role === 'ram' ? $found[0] : $found[1],
            default => null,
        };

        if ($size === null || ($range && ($size < $range[0] || $size > $range[1]))) {
            return null;
        }

        $unit = $size >= Measure::factor('memory', 'tb') ? 'tb' : 'gb';

        return Measure::canonical(['dimension' => 'memory', 'unit' => $unit, 'low' => $size, 'high' => null]);
    }

    /*
     | The colour a name says, written the way this product's own family
     | already writes it -- so the picker on the product page does not offer
     | "Black" beside "შავი" -- or, failing that, the way most of the shop does.
     */
    protected function colourFor(Attribute $attribute, Product $product): ?string
    {
        $base = Palette::named((string) $product->name, $product->brand?->name);

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
                && ($answer->reading['high'] === null) === ($wanted['high'] === null)
                && ($wanted['high'] === null || abs($answer->reading['high'] - $wanted['high']) <= $wanted['high'] * self::SAME))
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
