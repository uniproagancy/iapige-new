<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\SupplierStock;
use Illuminate\Console\Command;

/*
 | Reads nothing but tells everything: what a supplier's rows are keyed by,
 | what its products are called because of it, and whether two rows have landed
 | on the same name. Changes nothing.
 */
class InspectSupplierKeys extends Command
{
    protected $signature = 'import:keys
        {supplier : the supplier code}
        {--show=8 : how many examples to print}';

    protected $description = 'Show how a supplier price list is keyed, and what it named the products';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->argument('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->argument('supplier')}].");

            return self::FAILURE;
        }

        $this->line('');
        $this->info($supplier->name.'  —  key column: '.($supplier->config['columns']['key'] ?? '?')
            .($supplier->config['columns']['key_as'] ?? null ? ' (read as a url)' : ''));

        $this->rows($supplier);
        $this->products($supplier);

        return self::SUCCESS;
    }

    protected function rows(Supplier $supplier): void
    {
        $rows = SupplierStock::where('supplier_id', $supplier->id)->count();

        if (! $rows) {
            $this->warn('  no price list is loaded for this supplier.');

            return;
        }

        $urls = SupplierStock::where('supplier_id', $supplier->id)
            ->where('external_id', 'like', 'http%')
            ->count();

        $longest = SupplierStock::where('supplier_id', $supplier->id)
            ->get(['external_id'])
            ->map(fn (SupplierStock $row) => mb_strlen((string) $row->external_id))
            ->max();

        $this->newLine();
        $this->table(['the price list', 'rows'], [
            ['loaded', $rows],
            ['keyed by a web address', $urls],
            ['longest key, in characters', $longest],
        ]);

        $this->line('  the first few keys:');

        SupplierStock::where('supplier_id', $supplier->id)
            ->limit((int) $this->option('show'))
            ->get(['external_id', 'data'])
            ->each(function (SupplierStock $row) {
                $url = ((array) ($row->data ?? []))['url'] ?? null;

                $this->line('    '.mb_substr((string) $row->external_id, 0, 70)
                    .($url ? '   ← '.mb_substr((string) $url, 0, 50) : ''));
            });
    }

    protected function products(Supplier $supplier): void
    {
        $offers = ProductOffer::where('supplier_id', $supplier->id)->count();

        if (! $offers) {
            $this->newLine();
            $this->warn('  nothing from this supplier has reached the shop yet.');

            return;
        }

        $ids = ProductOffer::where('supplier_id', $supplier->id)->pluck('product_id');

        $named = Product::whereIn('id', $ids)->where('sku', 'like', '%http%')->count();

        $this->newLine();
        $this->table(['in the shop', 'products'], [
            ['from this supplier', $ids->unique()->count()],
            ['whose code is a web address', $named],
        ]);

        if ($named) {
            $this->warn('  Those products are named after a web address, which is what the');
            $this->line('  shop shows as the supplier code. They were created by a price list');
            $this->line('  whose key column held links rather than codes.');

            $this->newLine();
            $this->line('  the first few:');

            Product::whereIn('id', $ids)->where('sku', 'like', '%http%')
                ->limit((int) $this->option('show'))
                ->get(['id', 'sku'])
                ->each(fn (Product $p) => $this->line('    #'.$p->id.'  '.mb_substr($p->sku, 0, 70)));
        }
    }
}
