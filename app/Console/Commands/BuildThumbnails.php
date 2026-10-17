<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\Images\Thumbnailer;
use Illuminate\Console\Command;

class BuildThumbnails extends Command
{
    protected $signature = 'images:thumbs
        {--force : rebuild the ones that already have thumbnails}
        {--limit=0 : stop after this many images}';

    protected $description = 'Build the resized webp copies the storefront serves';

    public function handle(Thumbnailer $thumbnailer): int
    {
        if (! Thumbnailer::supported()) {
            $this->error('This PHP has no webp support in GD.');

            return self::FAILURE;
        }

        $query = ProductImage::query()
            ->where('path', 'not like', 'http%')
            ->when(! $this->option('force'), fn ($q) => $q->where('has_thumbs', false))
            ->orderBy('id');

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to build.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = $failed = 0;

        $query->chunkById(100, function ($images) use ($thumbnailer, $bar, &$done, &$failed) {
            foreach ($images as $image) {
                $thumbnailer->make($image, force: (bool) $this->option('force'))
                    ? $done++
                    : $failed++;

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);
        $this->info("Built {$done} of {$total}.");

        if ($failed) {
            $this->warn("{$failed} could not be read — check storage/logs for the paths.");
        }

        return self::SUCCESS;
    }
}
