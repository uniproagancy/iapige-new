<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Support\ProductNames;
use App\Support\SpecTerms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 | Products whose Georgian page still read in the supplier's English.
 |
 | Names are put into Georgian by App\Support\ProductNames. Descriptions are
 | translated text, handed over as JSON files of {"product id": "<html>"}.
 | Before a Georgian row is changed the English it had is copied to the
 | product's English row, so the English page does not change, and to a file,
 | so it can be taken back. Imports leave Georgian alone after that
 | (ProductImporter::keepGeorgian).
 */
class GeorgianProducts extends Command
{
    protected $signature = 'products:georgian
        {--names : put English names into Georgian}
        {--descriptions=* : JSON files of translated descriptions, {"id": "html"}}
        {--apply : write the changes; without it only counts}';

    protected $description = 'Put English product names and descriptions on the Georgian site into Georgian';

    private const COPIED = ['name', 'summary', 'description', 'meta_title', 'meta_description'];

    private string $backup;

    public function handle(): int
    {
        $this->backup = 'backups/products_georgian_'.now()->format('Ymd_His').'.jsonl';
        $apply = (bool) $this->option('apply');

        if ($this->option('names')) {
            $this->names($apply);
        }

        foreach ((array) $this->option('descriptions') as $file) {
            $this->descriptions($file, $apply);
        }

        if (! $this->option('names') && ! $this->option('descriptions')) {
            $this->error('Say what to do: --names, --descriptions=file.json, or both.');

            return self::FAILURE;
        }

        $this->info($apply ? "Done. What was there before is in storage/app/{$this->backup}" : 'Nothing written; run again with --apply.');

        return self::SUCCESS;
    }

    private function names(bool $apply): void
    {
        $count = 0;
        $examples = [];

        DB::table('product_translations')->where('locale', Language::defaultCode())->orderBy('id')
            ->chunkById(1000, function ($rows) use ($apply, &$count, &$examples) {
                $changes = [];

                foreach ($rows as $row) {
                    $georgian = ProductNames::georgian($row->name);

                    if ($georgian !== null && $georgian !== $row->name) {
                        $changes[$row->product_id] = [$row, ['name' => $georgian]];

                        if (count($examples) < 15) {
                            $examples[] = "{$row->name}\n      → {$georgian}";
                        }
                    }
                }

                $count += count($changes);

                if ($apply && $changes) {
                    $this->write($changes);
                }
            });

        $this->line("names: {$count} to Georgian");

        foreach ($examples as $example) {
            $this->line("    {$example}");
        }
    }

    private function descriptions(string $file, bool $apply): void
    {
        $given = json_decode((string) @file_get_contents($file), true);

        if (! is_array($given)) {
            $this->error("{$file}: not a JSON object of id => description");

            return;
        }

        $rows = DB::table('product_translations')->where('locale', Language::defaultCode())
            ->whereIn('product_id', array_keys($given))->get()->keyBy('product_id');

        $changes = [];
        $missing = 0;

        foreach ($given as $id => $html) {
            $row = $rows[$id] ?? null;

            if (! $row || ! is_string($html) || ! preg_match('/\p{Georgian}/u', $html)) {
                $missing++;

                continue;
            }

            $changes[$row->product_id] = [$row, ['description' => $html]];
        }

        $this->line(basename($file).': '.count($changes).' descriptions to Georgian'.($missing ? ", {$missing} skipped" : ''));

        if ($apply && $changes) {
            $this->write($changes);
        }
    }

    /* $changes: product id => [the Georgian row as it is, the fields to set] */
    private function write(array $changes): void
    {
        $source = SpecTerms::SOURCE;
        $english = DB::table('product_translations')->where('locale', $source)
            ->whereIn('product_id', array_keys($changes))->get()->keyBy('product_id');
        $takenSlugs = DB::table('product_translations')->where('locale', $source)
            ->whereIn('slug', array_map(fn ($c) => $c[0]->slug, $changes))->pluck('product_id', 'slug');

        $lines = [];

        DB::transaction(function () use ($changes, $english, $takenSlugs, $source, &$lines) {
            foreach ($changes as $productId => [$row, $fields]) {
                $lines[] = json_encode(['product_id' => $productId] + array_intersect_key((array) $row, $fields), JSON_UNESCAPED_UNICODE);
                $had = $english[$productId] ?? null;

                /* What the English page reads must not turn Georgian with it:
                   every field it has empty falls back to the Georgian row. */
                $copy = [];

                foreach (self::COPIED as $field) {
                    if (! filled($had?->$field) && filled($row->$field)) {
                        $copy[$field] = $row->$field;
                    }
                }

                if (! $had) {
                    $slug = $row->slug;

                    if (isset($takenSlugs[$slug]) && (int) $takenSlugs[$slug] !== (int) $productId) {
                        $slug .= '-'.$productId;
                    }

                    DB::table('product_translations')->insert(['product_id' => $productId, 'locale' => $source, 'slug' => $slug] + $copy);
                } elseif ($copy) {
                    DB::table('product_translations')->where('id', $had->id)->update($copy);
                }

                DB::table('product_translations')->where('id', $row->id)->update($fields);
            }
        });

        Storage::append($this->backup, implode("\n", $lines));
    }
}
