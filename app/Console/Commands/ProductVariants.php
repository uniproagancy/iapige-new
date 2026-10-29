<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\VariantKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/*
 | Two things hide among a supplier's products: one product sent as several,
 | once per colour or capacity, and one product sent twice over. The first is a
 | family and can be tied together; the second is a mistake and only worth
 | pointing at. Reports both by default -- the families are a guess from the
 | names, so they are read before they are written.
 */
class ProductVariants extends Command
{
    protected $signature = 'products:variants
        {--supplier=alta : whose products to look at, by code}
        {--apply : file the families, which only reporting does not}
        {--show=30 : how many of each to print}';

    protected $description = 'Find a supplier\'s products that are one product in several colours or sizes, and the ones sent twice';

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

        $this->reportDoubles($products);

        return $this->fileFamilies($supplier->code, $products);
    }

    /*
     | The same name twice is not a choice to offer a customer, so these are
     | never tied together -- one of them wants deleting or correcting, and
     | only the person who imported them knows which.
     */
    protected function reportDoubles(Collection $products): void
    {
        $doubles = $products
            ->groupBy(fn (Product $p) => VariantKey::normalise((string) $p->name))
            ->filter(fn (Collection $rows, $name) => $name !== '' && $rows->count() > 1);

        if ($doubles->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line($doubles->count().' products imported more than once:');

        $this->table(
            ['name', 'how many', 'skus'],
            $doubles->take((int) $this->option('show'))->map(fn (Collection $rows) => [
                (string) $rows->first()->name,
                $rows->count(),
                $rows->pluck('sku')->implode("\n"),
            ])->values()->all(),
        );
    }

    protected function fileFamilies(string $supplierCode, Collection $products): int
    {
        $families = $products
            ->map(fn (Product $product) => [
                'product' => $product,
                'key' => $this->keyFor($supplierCode, $product),
            ])
            ->filter(fn (array $row) => $row['key'] !== null)
            ->groupBy('key')
            /* Two of the same name are a double, not a choice; a family needs
               at least two products a customer could tell apart. */
            ->filter(fn (Collection $rows) => $rows
                ->map(fn (array $row) => VariantKey::normalise((string) $row['product']->name))
                ->unique()
                ->count() > 1);

        /* A group somebody has already made is theirs, whatever the names say
           about it now. */
        $established = Product::query()
            ->whereNotNull('variant_group')
            ->selectRaw('variant_group, count(*) as members')
            ->groupBy('variant_group')
            ->having('members', '>', 1)
            ->pluck('members', 'variant_group');

        $apply = (bool) $this->option('apply');
        $shown = (int) $this->option('show');
        $written = $kept = 0;
        $rows = [];

        foreach ($families as $key => $members) {
            $family = $members->pluck('product');

            $already = $family->filter(fn (Product $p) => $p->variant_group
                && $p->variant_group !== $key
                && $established->has($p->variant_group));

            $kept += $already->count();

            $toWrite = $family
                ->reject(fn (Product $p) => $already->contains(fn (Product $o) => $o->is($p)))
                ->reject(fn (Product $p) => $p->variant_group === $key);

            if (count($rows) < $shown) {
                $rows[] = [
                    $key,
                    $family->count(),
                    $family->take(4)->map(fn (Product $p) => (string) $p->name)->implode("\n"),
                ];
            }

            if ($apply && $toWrite->isNotEmpty()) {
                Product::whereIn('id', $toWrite->pluck('id'))->update(['variant_group' => $key]);
            }

            $written += $toWrite->count();
        }

        if ($rows) {
            $this->line('');
            $this->table(['family', 'products', 'what is in it'], $rows);
        }

        $this->line('');
        $this->line($families->count().' families of more than one');
        $this->line($apply
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
