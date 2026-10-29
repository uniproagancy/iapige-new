<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\VariantKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/*
 | Ties together what a supplier sent as separate products but is one product
 | in several colours or capacities. Reports by default: grouping is a guess
 | from the names, so it is read before it is written.
 */
class ProductVariants extends Command
{
    protected $signature = 'products:variants
        {--supplier=alta : whose products to look at, by code}
        {--apply : write the groups instead of only reporting them}
        {--show=30 : how many groups to print}';

    protected $description = 'Group a supplier\'s products that are one product in several colours or sizes';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->option('supplier'))->first();

        if (! $supplier) {
            $this->error('No supplier with code '.$this->option('supplier'));

            return self::FAILURE;
        }

        $products = Product::query()
            ->whereHas('offers', fn ($q) => $q->where('supplier_id', $supplier->id))
            ->with(['brand', 'attributeValues' => fn ($q) => $q->withTranslation()->with('attribute')])
            ->withTranslation()
            ->get();

        if ($products->isEmpty()) {
            $this->line('Nothing imported from '.$supplier->code.' yet.');

            return self::SUCCESS;
        }

        $this->line($products->count().' products from '.$supplier->code);

        $families = $products
            ->map(fn (Product $product) => [
                'product' => $product,
                'key' => $this->keyFor($supplier->code, $product),
            ])
            ->filter(fn (array $row) => $row['key'] !== null)
            ->groupBy('key')
            ->filter(fn (Collection $rows) => $rows->count() > 1);

        /* A group somebody has already made is theirs, whatever the names say
           about it now. */
        $established = Product::query()
            ->whereNotNull('variant_group')
            ->selectRaw('variant_group, count(*) as members')
            ->groupBy('variant_group')
            ->having('members', '>', 1)
            ->pluck('members', 'variant_group');

        $written = $kept = 0;
        $shown = (int) $this->option('show');
        $rows = [];

        foreach ($families as $key => $members) {
            $products = $members->pluck('product');
            $already = $products->filter(fn (Product $p) => $p->variant_group
                && $p->variant_group !== $key
                && $established->has($p->variant_group));

            if ($already->isNotEmpty()) {
                $kept += $already->count();
            }

            $toWrite = $products->reject(fn (Product $p) => $already->contains(fn (Product $o) => $o->is($p)))
                ->reject(fn (Product $p) => $p->variant_group === $key);

            if (count($rows) < $shown) {
                $rows[] = [
                    $key,
                    $products->count(),
                    $products->take(4)->map(fn (Product $p) => $p->name)->implode("\n"),
                ];
            }

            if ($this->option('apply') && $toWrite->isNotEmpty()) {
                Product::whereIn('id', $toWrite->pluck('id'))->update(['variant_group' => $key]);
                $written += $toWrite->count();
            } elseif ($toWrite->isNotEmpty()) {
                $written += $toWrite->count();
            }
        }

        if ($rows) {
            $this->table(['group', 'products', 'what is in it'], $rows);
        }

        $this->line('');
        $this->line($families->count().' families of more than one');
        $this->line($this->option('apply')
            ? $written.' products filed under one'
            : $written.' products would be filed under one (pass --apply to write)');

        if ($kept) {
            $this->line($kept.' left alone, already grouped by hand');
        }

        return self::SUCCESS;
    }

    protected function keyFor(string $supplierCode, Product $product): ?string
    {
        $name = trim((string) $product->name);

        if ($name === '') {
            return null;
        }

        $distinguishing = $product->attributeValues
            ->filter(fn ($value) => $value->attribute && VariantKey::isAxis((string) $value->attribute->code))
            ->map(fn ($value) => (string) $value->label)
            ->filter()
            ->values()
            ->all();

        return VariantKey::make($supplierCode, $product->brand?->name, $name, $distinguishing);
    }
}
