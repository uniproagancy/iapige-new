<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 | Two suppliers call the same thing by different names and the shop ends up
 | with "დიაგონალი" and "ეკრანის ზომა" side by side, each filtering a share of
 | the products. attributes:doctor finds them; this one joins them up.
 */
class MergeAttributes extends Command
{
    protected $signature = 'attributes:merge
        {from : the attribute code to fold away}
        {into : the attribute code to keep}
        {--dry : show what would move and stop}';

    protected $description = 'Fold one attribute into another, with its values, products and supplier keys';

    public function handle(): int
    {
        $from = Attribute::where('code', $this->argument('from'))->withTranslation()->first();
        $into = Attribute::where('code', $this->argument('into'))->withTranslation()->first();

        if (! $from || ! $into) {
            $this->error('Both codes have to name an attribute that exists.');

            return self::FAILURE;
        }

        if ($from->is($into)) {
            $this->error('They are the same attribute.');

            return self::FAILURE;
        }

        $values = DB::table('attribute_values')->where('attribute_id', $from->id)->count();
        $specs = DB::table('product_specs')->where('attribute_id', $from->id)->count();
        $keys = DB::table('supplier_attribute_map')->where('attribute_id', $from->id)->count();

        $this->table(['', 'from '.$from->code, 'into '.$into->code], [
            ['name', $from->name, $into->name],
            ['values', $values, DB::table('attribute_values')->where('attribute_id', $into->id)->count()],
            ['products', $specs, DB::table('product_specs')->where('attribute_id', $into->id)->count()],
            ['supplier keys', $keys, DB::table('supplier_attribute_map')->where('attribute_id', $into->id)->count()],
        ]);

        if ($this->option('dry')) {
            $this->newLine();
            $this->info('Nothing was changed. Run it without --dry to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($from, $into) {
            $this->moveValues($from, $into);
            $this->moveSpecs($from, $into);

            DB::table('supplier_attribute_map')
                ->where('attribute_id', $from->id)
                ->update(['attribute_id' => $into->id]);

            $from->delete();
        });

        $this->newLine();
        $this->info("{$from->code} was folded into {$into->code}.");
        $this->line('  Run attributes:tidy next, to fold the spellings of its values together.');

        return self::SUCCESS;
    }

    /* A value whose code is already taken on the other side hands its products
       over and goes; the rest simply change sides. */
    protected function moveValues(Attribute $from, Attribute $into): void
    {
        $taken = DB::table('attribute_values')
            ->where('attribute_id', $into->id)
            ->pluck('id', 'code');

        $moving = DB::table('attribute_values')->where('attribute_id', $from->id)->get(['id', 'code']);

        foreach ($moving as $value) {
            if (! $keepId = $taken[$value->code] ?? null) {
                DB::table('attribute_values')->where('id', $value->id)->update(['attribute_id' => $into->id]);

                continue;
            }

            $products = DB::table('attribute_value_product')
                ->where('attribute_value_id', $value->id)
                ->pluck('product_id');

            foreach ($products as $productId) {
                DB::table('attribute_value_product')->updateOrInsert([
                    'attribute_value_id' => $keepId,
                    'product_id' => $productId,
                ]);
            }

            DB::table('attribute_value_product')->where('attribute_value_id', $value->id)->delete();
            DB::table('attribute_values')->where('id', $value->id)->delete();
        }
    }

    /* A product cannot carry the same attribute twice, so where it already has
       the one we keep, the spare row is dropped rather than moved. */
    protected function moveSpecs(Attribute $from, Attribute $into): void
    {
        $already = DB::table('product_specs')
            ->where('attribute_id', $into->id)
            ->pluck('product_id')
            ->flip();

        $rows = DB::table('product_specs')->where('attribute_id', $from->id)->get(['id', 'product_id']);

        foreach ($rows as $row) {
            isset($already[$row->product_id])
                ? DB::table('product_specs')->where('id', $row->id)->delete()
                : DB::table('product_specs')->where('id', $row->id)->update(['attribute_id' => $into->id]);
        }
    }
}
