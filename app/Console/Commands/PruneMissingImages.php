<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Models\ProductOffer;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the record of photographs that are not on disk.
 *
 * The public disk is configured not to throw, so a write it could not make
 * returned false and said nothing. The return value was not read, so every
 * image of every product got a row pointing at a file that was never there.
 *
 * That is fixed, but the rows it already wrote are still in the way — and in
 * a particular manner. The importer only re-fetches a gallery when the product
 * has fewer rows than the supplier offers, so a product with three phantom
 * rows and three pictures on offer counts as complete and is never asked
 * about again. The admin agrees it has photographs, because the "no photo"
 * filter counts rows. The shop serves broken images for ever.
 *
 * Clearing the rows is what lets the next import fetch them. A file that is
 * genuinely on disk is left alone, and the downloader names a file after the
 * product and the source URL, so anything already there costs no request.
 */
class PruneMissingImages extends Command
{
    protected $signature = 'images:prune
                            {--supplier= : only this supplier’s products}
                            {--force : actually delete; without it nothing is touched}
                            {--chunk=500}';

    protected $description = 'Delete image rows whose file is missing, so the next import fetches them again';

    public function handle(): int
    {
        $query = ProductImage::query();

        if ($code = $this->option('supplier')) {
            $supplier = Supplier::where('code', $code)->first();

            if (! $supplier) {
                $this->error("No supplier with code [{$code}].");

                return self::FAILURE;
            }

            $query->whereIn(
                'product_id',
                ProductOffer::where('supplier_id', $supplier->id)->select('product_id'),
            );
        }

        $disk = Storage::disk('public');
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No image rows to check.');

            return self::SUCCESS;
        }

        $this->line("Checking {$total} image rows…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $missing = [];

        (clone $query)->select(['id', 'product_id', 'path'])
            ->chunkById((int) $this->option('chunk'), function ($rows) use ($disk, &$missing, $bar) {
                foreach ($rows as $row) {
                    if (! $disk->exists($row->path)) {
                        $missing[] = $row->id;
                    }
                }

                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine(2);

        if (! $missing) {
            $this->info('Every recorded photograph is on disk.');

            return self::SUCCESS;
        }

        $this->warn(count($missing).' of '.$total.' recorded photographs are not on disk.');
        $this->line('  Disk root: '.config('filesystems.disks.public.root'));

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Nothing was deleted. Add --force to do it for real.');

            return self::SUCCESS;
        }

        foreach (array_chunk($missing, 1000) as $chunk) {
            ProductImage::whereIn('id', $chunk)->delete();
        }

        $this->newLine();
        $this->info(count($missing).' rows removed. The next import will fetch those pictures again.');

        return self::SUCCESS;
    }
}
