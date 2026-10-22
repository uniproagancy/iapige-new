<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Images\ImageDeduper;
use Illuminate\Console\Command;

class DedupeProductImages extends Command
{
    protected $signature = 'images:dedupe
        {--dry : count the twins and stop}
        {--product= : one product id}';

    protected $description = 'Drop product photos that are copies of another photo of the same product';

    public function handle(ImageDeduper $deduper): int
    {
        $dry = (bool) $this->option('dry');

        $query = Product::has('images', '>', 1)
            ->when($this->option('product'), fn ($q) => $q->whereKey($this->option('product')));

        $total = $query->count();

        if (! $total) {
            $this->info('No product carries more than one photo.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $removed = 0;
        $touched = 0;

        $query->chunkById(100, function ($products) use ($deduper, $dry, $bar, &$removed, &$touched) {
            foreach ($products as $product) {
                $n = $deduper->prune($product, $dry);

                if ($n) {
                    $removed += $n;
                    $touched++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if (! $removed) {
            $this->info('Nothing is duplicated.');

            return self::SUCCESS;
        }

        $this->info($dry
            ? "{$removed} duplicate photo(s) on {$touched} product(s). Run it without --dry to remove them."
            : "{$removed} duplicate photo(s) removed from {$touched} product(s).");

        return self::SUCCESS;
    }
}
