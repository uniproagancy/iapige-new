<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 | Suppliers write the same thing in their own way: 55", 55 inch, 55 ინჩი. Each
 | spelling became a filter of its own, so choosing one found a tenth of the
 | products it should have. This folds the spellings of a value into one.
 */
class MergeAttributeValues extends Command
{
    protected $signature = 'attributes:tidy
        {--attribute= : only this attribute code}
        {--dry : show what would be folded together and stop}
        {--limit=40 : how many groups to print}';

    protected $description = 'Fold differently spelled values of the same attribute into one';

    public function handle(): int
    {
        $attributes = Attribute::query()
            ->when($this->option('attribute'), fn ($q) => $q->where('code', $this->option('attribute')))
            ->get();

        if ($attributes->isEmpty()) {
            $this->error('No such attribute.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $rows = [];
        $folded = 0;
        $groups = 0;

        foreach ($attributes as $attribute) {
            foreach ($this->twins($attribute) as $spelling => $values) {
                $keep = $this->mostUsed($values);
                $rest = $values->reject(fn (AttributeValue $v) => $v->is($keep));

                if ($rest->isEmpty()) {
                    continue;
                }

                $groups++;
                $folded += $rest->count();

                if (count($rows) < (int) $this->option('limit')) {
                    $rows[] = [
                        $attribute->code,
                        $keep->label.' ('.$this->uses($keep).')',
                        $rest->map(fn ($v) => $v->label.' ('.$this->uses($v).')')->implode(' · '),
                    ];
                }

                if (! $dry) {
                    $this->fold($rest, $keep);
                }
            }
        }

        if (! $groups) {
            $this->info('Every value is spelled one way already.');

            return self::SUCCESS;
        }

        $this->table(['attribute', 'kept', 'folded into it'], $rows);

        $this->newLine();
        $this->line("  groups: {$groups}");
        $this->line(($dry ? '  would be folded: ' : '  folded: ')."{$folded}");

        if ($dry) {
            $this->newLine();
            $this->info('Nothing was changed. Run it without --dry to apply.');
        }

        return self::SUCCESS;
    }

    /* Values of one attribute, grouped by how they read once the punctuation
       and the unit are taken off. */
    protected function twins(Attribute $attribute)
    {
        return AttributeValue::where('attribute_id', $attribute->id)
            ->withTranslation()
            ->get()
            ->groupBy(fn (AttributeValue $value) => self::spelling((string) $value->label))
            ->reject(fn ($values, $spelling) => $spelling === '' || $values->count() < 2);
    }

    /* 55", 55 inch and 55 ინჩი all read as "55". */
    public static function spelling(string $label): string
    {
        $label = mb_strtolower(trim($label));

        $label = str_replace(['"', '”', '“', '″', "'", '’'], ' ', $label);
        $label = preg_replace('~\b(inch|inches|ინჩი|დუიმი)\b~u', ' ', $label);
        $label = str_replace(',', '.', $label);

        /* 55.0 and 55 are the same size */
        $label = preg_replace('~(\d)\.0+\b~u', '$1', (string) $label);

        return trim((string) preg_replace('~[\s\x{00A0}_-]+~u', ' ', (string) $label));
    }

    protected function mostUsed($values): AttributeValue
    {
        return $values->sortByDesc(fn (AttributeValue $v) => $this->uses($v))->first();
    }

    protected function uses(AttributeValue $value): int
    {
        return $this->counts[$value->id] ??= DB::table('attribute_value_product')
            ->where('attribute_value_id', $value->id)
            ->count();
    }

    protected array $counts = [];

    protected function fold($rest, AttributeValue $keep): void
    {
        DB::transaction(function () use ($rest, $keep) {
            foreach ($rest as $value) {
                $products = DB::table('attribute_value_product')
                    ->where('attribute_value_id', $value->id)
                    ->pluck('product_id');

                foreach ($products as $productId) {
                    /* The product may already carry the kept spelling. */
                    DB::table('attribute_value_product')->updateOrInsert([
                        'attribute_value_id' => $keep->id,
                        'product_id' => $productId,
                    ]);
                }

                DB::table('attribute_value_product')->where('attribute_value_id', $value->id)->delete();

                $value->delete();
            }
        });

        unset($this->counts[$keep->id]);
    }
}
