<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Support\SpecTerms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 | Gives the Georgian to the specifications already in the shop. Imports do
 | it from now on (ProductImporter, TaxonomyResolver); this is for what came
 | in before. The English each row had goes to its English row, and to a
 | file, so a word put wrong in the glossary can be taken back.
 */
class GeorgianSpecTerms extends Command
{
    protected $signature = 'specs:georgian {--apply : write the changes; without it only counts}';

    protected $description = 'Translate English specification names and values into Georgian using lang/ka/spec_terms.php';

    private const TABLES = [
        'attribute_translations' => ['attribute_id', 'name'],
        'attribute_value_translations' => ['attribute_value_id', 'label'],
        'product_spec_translations' => ['product_spec_id', 'value'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $default = Language::defaultCode();
        $backup = 'backups/spec_terms_'.now()->format('Ymd_His').'.jsonl';

        foreach (self::TABLES as $table => [$owner, $field]) {
            $changed = $added = 0;
            $examples = [];

            DB::table($table)->where('locale', $default)->orderBy('id')
                ->chunkById(2000, function ($rows) use ($table, $owner, $field, $apply, $backup, &$changed, &$added, &$examples) {
                    $said = [];

                    foreach ($rows as $row) {
                        $georgian = SpecTerms::georgian($row->$field);

                        if ($georgian !== null && $georgian !== $row->$field) {
                            $said[$row->id] = [$row, $georgian];
                        }
                    }

                    if (! $said) {
                        return;
                    }

                    $owners = array_map(fn ($pair) => $pair[0]->$owner, $said);
                    $haveEnglish = DB::table($table)->where('locale', SpecTerms::SOURCE)
                        ->whereIn($owner, $owners)->whereNotNull($field)->where($field, '!=', '')
                        ->pluck($owner)->flip();

                    $english = [];
                    $lines = '';

                    foreach ($said as $id => [$row, $georgian]) {
                        if (count($examples) < 8) {
                            $examples[] = "{$row->$field} → {$georgian}";
                        }

                        if (! isset($haveEnglish[$row->$owner])) {
                            $english[] = [$owner => $row->$owner, 'locale' => SpecTerms::SOURCE, $field => $row->$field];
                        }

                        $lines .= json_encode(['table' => $table, 'id' => $id, $field => $row->$field], JSON_UNESCAPED_UNICODE)."\n";
                    }

                    $changed += count($said);
                    $added += count($english);

                    if (! $apply) {
                        return;
                    }

                    Storage::append($backup, rtrim($lines, "\n"));

                    DB::transaction(function () use ($table, $owner, $field, $said, $english) {
                        if ($english) {
                            DB::table($table)->upsert($english, [$owner, 'locale'], [$field]);
                        }

                        foreach ($said as $id => [, $georgian]) {
                            DB::table($table)->where('id', $id)->update([$field => $georgian]);
                        }
                    });
                });

            $this->line("{$table}: {$changed} to Georgian, {$added} English rows kept aside");

            foreach ($examples as $example) {
                $this->line("    {$example}");
            }
        }

        $this->info($apply ? "Done. The English before is in storage/app/{$backup}" : 'Nothing written; run again with --apply.');

        return self::SUCCESS;
    }
}
