<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 | The delivery fee is worked out from what an order weighs, and almost nothing
 | in the catalogue carries a weight -- although the suppliers did send one, as
 | a line in the specifications: "217 g", "2.7კგ", "0.340 კგ". This reads those
 | lines and writes the figure where the shop looks for it.
 |
 | Only fills what is empty, so a weight somebody typed in by hand stands.
 */
class ProductWeights extends Command
{
    /*
     | Which line to believe, best first. A product's own weight comes before
     | the weight of its box, and lines that measure only a part of it -- the
     | indoor unit of an air conditioner, a television without its stand -- are
     | left out of this list entirely.
     */
    public const SOURCES = [
        'tsona',
        'produktis-tsona',
        'net-tsona-kg',
        'net-tsona',
        'netgros-tsona',
        'netgross-tsona-kg',
        'zoma-tsona-tsona-kg',
        'tsona-shefutvit',
        'gros-tsona-kg',
        'tsona-sadgamit',
        'tsona-sadgamis-chatvlit',
    ];

    /* A gram and two hundred kilogrammes; anything outside that is a figure
       that means something else. */
    public const LIGHTEST = 1;

    public const HEAVIEST = 200_000;

    protected $signature = 'products:weights
        {--apply : write the weights, which only reporting does not}
        {--show=15 : how many examples to print}';

    protected $description = 'Fill in a product\'s weight from the weight its supplier already sent';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $locale = Language::defaultCode();

        $attributes = Attribute::whereIn('code', self::SOURCES)->pluck('id', 'code');

        if ($attributes->isEmpty()) {
            $this->error('No weight specification to read.');

            return self::FAILURE;
        }

        /* Best source first, so the first answer found for a product is the
           one to believe. */
        $order = collect(self::SOURCES)
            ->filter(fn (string $code) => $attributes->has($code))
            ->values();

        $rank = $order->flip();

        $answers = DB::table('attribute_value_product')
            ->join('attribute_values', 'attribute_values.id', '=', 'attribute_value_product.attribute_value_id')
            ->join('attribute_value_translations', function ($join) use ($locale) {
                $join->on('attribute_value_translations.attribute_value_id', '=', 'attribute_values.id')
                    ->where('attribute_value_translations.locale', '=', $locale);
            })
            ->join('products', 'products.id', '=', 'attribute_value_product.product_id')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->whereIn('attributes.code', $order)
            ->where(fn ($q) => $q->whereNull('products.weight')->orWhere('products.weight', 0))
            ->select([
                'attribute_value_product.product_id',
                'attributes.code as source',
                'attribute_value_translations.label',
            ])
            ->get();

        $best = [];
        $unreadable = 0;

        foreach ($answers as $answer) {
            $grams = self::grams((string) $answer->label);

            if ($grams === null) {
                $unreadable++;

                continue;
            }

            $place = $rank[$answer->source] ?? 99;
            $held = $best[$answer->product_id] ?? null;

            if (! $held || $place < $held['place']) {
                $best[$answer->product_id] = [
                    'grams' => $grams,
                    'place' => $place,
                    'said' => (string) $answer->label,
                    'source' => $answer->source,
                ];
            }
        }

        $empty = DB::table('products')
            ->where(fn ($q) => $q->whereNull('weight')->orWhere('weight', 0))
            ->count();

        $this->line($empty.' products carry no weight');
        $this->line(count($best).' of them say what they weigh somewhere in their specifications');

        if ($unreadable) {
            $this->line($unreadable.' answers could not be read as a weight and were left alone');
        }

        if (! $best) {
            if ($this->option('apply')) {
                $this->line('0 weights written');
            }

            return self::SUCCESS;
        }

        $this->line('');

        foreach (array_slice($best, 0, (int) $this->option('show'), true) as $id => $found) {
            $this->line('  #'.str_pad((string) $id, 7).str_pad($found['said'], 16)
                .'→ '.str_pad($found['grams'].' g', 12).$found['source']);
        }

        $this->line('');

        if (! $this->option('apply')) {
            $this->line(count($best).' weights would be written (pass --apply to write them)');

            return self::SUCCESS;
        }

        /* Grouped by the figure, so one statement settles every product that
           weighs the same rather than one statement each. */
        $byWeight = [];

        foreach ($best as $id => $found) {
            $byWeight[$found['grams']][] = $id;
        }

        $written = 0;

        foreach ($byWeight as $grams => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $written += DB::table('products')->whereIn('id', $chunk)
                    ->where(fn ($q) => $q->whereNull('weight')->orWhere('weight', 0))
                    ->update(['weight' => $grams]);
            }
        }

        $this->line($written.' weights written');

        return self::SUCCESS;
    }

    /*
     | "217 g", "2.7კგ", "0.340 კგ", "3,370 კგ". The comma is a decimal point
     | where it is followed by a kilogramme figure and a thousands separator
     | where the answer is already in grammes, which is the reading that leaves
     | a sane weight either way.
     */
    public static function grams(string $said): ?int
    {
        $said = mb_strtolower(trim($said));
        $said = str_replace(["\u{00a0}", ' '], ' ', $said);

        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|კგ|kilogram\w*|g|გ|gr|გრ|gram\w*|lbs?|lb)\b/u', $said, $m)) {
            return null;
        }

        [$number, $unit] = [$m[1], $m[2]];

        $heavy = in_array($unit, ['kg', 'კგ'], true) || str_starts_with($unit, 'kilogram');

        if (str_contains($number, ',')) {
            $decimals = mb_strlen(mb_substr($number, mb_strpos($number, ',') + 1));

            $number = $decimals === 3 && ! $heavy
                ? str_replace(',', '', $number)
                : str_replace(',', '.', $number);
        }

        $number = (float) $number;

        if ($number <= 0) {
            return null;
        }

        /* Milligrammes are not a shipping weight, so a line written in them
           is read as saying nothing rather than as a gramme. */
        $grams = match (true) {
            $heavy => $number * 1000,
            in_array($unit, ['lb', 'lbs'], true) => $number * 453.592,
            default => $number,
        };

        $grams = (int) round($grams);

        return $grams >= self::LIGHTEST && $grams <= self::HEAVIEST ? $grams : null;
    }
}
