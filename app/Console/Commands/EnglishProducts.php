<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 | Products the English site showed in Georgian.
 |
 | Georgian suppliers send one name, in Georgian, and the English page fell
 | back to it -- so Google saw the Georgian page twice. A name is put into
 | English phrase by phrase from a glossary, {"georgian phrase": "english"},
 | leaving the brand and model as the maker wrote them. A name with a phrase
 | the glossary lacks is left alone and listed, never half-translated.
 |
 | Only the English row is written; the Georgian page does not change. What
 | an English row held before is copied to a backup file first. An empty
 | English description falls back to the Georgian one (HasTranslations), so
 | a row created here never blanks a page.
 */
class EnglishProducts extends Command
{
    protected $signature = 'products:english
        {--names= : glossary JSON of Georgian name phrases, {"georgian": "english"}}
        {--show=12 : how many changes, picked at random, to print for checking}
        {--apply : write the changes; without it only counts}';

    protected $description = 'Give products with only a Georgian name an English name for the English site';

    /* A run of Georgian words, with the spaces, commas and slashes Georgian names put between them */
    private const PHRASE = '/[\x{10D0}-\x{10FF}][\x{10D0}-\x{10FF}\-]*(?:[ ,\/]+[\x{10D0}-\x{10FF}][\x{10D0}-\x{10FF}\-]*)*/u';

    public function handle(): int
    {
        $glossary = json_decode((string) @file_get_contents((string) $this->option('names')), true);

        if (! is_array($glossary) || ! $glossary) {
            $this->error('Give the glossary: --names=file.json, {"georgian phrase": "english"}.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $default = Language::defaultCode();
        $english = 'en';

        $rows = DB::table('product_translations as k')
            ->join('products as p', 'p.id', '=', 'k.product_id')
            ->leftJoin('product_translations as e', fn ($join) => $join->on('e.product_id', '=', 'k.product_id')->where('e.locale', '=', $english))
            ->where('p.status', 'active')
            ->where('k.locale', $default)
            ->where(fn ($q) => $q->whereNull('e.id')->orWhereRaw("e.name regexp '[ა-ჰ]'"))
            ->get(['k.product_id', 'k.name', 'e.id as english_id', 'e.name as english_name']);

        $changes = [];
        $unknown = [];

        foreach ($rows as $row) {
            $name = $this->english((string) $row->name, $glossary, $unknown);

            if ($name !== null) {
                $changes[] = [$row, $name];
            }
        }

        $created = count(array_filter($changes, fn ($c) => $c[0]->english_id === null));
        $this->line(count($rows).' products show a Georgian name in English: '.count($changes).' get one'
            ." ({$created} new English rows, ".(count($changes) - $created).' corrected), '
            .(count($rows) - count($changes)).' left for a phrase the glossary lacks');

        $shown = $changes;
        shuffle($shown);

        foreach (array_slice($shown, 0, (int) $this->option('show')) as [$row, $name]) {
            $this->line("    {$row->name}\n      → {$name}");
        }

        if ($unknown) {
            arsort($unknown);
            $this->warn('Phrases not in the glossary: '.implode(' | ', array_slice(array_keys($unknown), 0, 40)));
        }

        if ($apply && $changes) {
            $backup = $this->write($changes, $english);
            $this->info("Done. What the English rows held before is in storage/app/{$backup}");
        } elseif (! $apply) {
            $this->info('Nothing written; run again with --apply.');
        }

        return self::SUCCESS;
    }

    /* The name in English, or null when some phrase in it has no translation. */
    private function english(string $name, array $glossary, array &$unknown): ?string
    {
        $missing = false;

        // "Ilife T10s -ის ფილტრი" is "Ilife T10s filter": the genitive ending goes with its phrase, the hyphen with it
        $name = preg_replace('/\s*-(?=ის(?:\s|$))/u', ' ', $name);

        $out = preg_replace_callback(self::PHRASE, function (array $m) use ($glossary, &$unknown, &$missing) {
            $phrase = trim($m[0]);

            if (! isset($glossary[$phrase])) {
                $unknown[$phrase] = ($unknown[$phrase] ?? 0) + 1;
                $missing = true;

                return $m[0];
            }

            return $glossary[$phrase];
        }, $name);

        if ($missing || preg_match('/\p{Georgian}/u', $out)) {
            return null;
        }

        // "Xiaomi-ის ფილტრი" is "Xiaomi's filter": the genitive went with the phrase, the hyphen stayed
        $out = preg_replace('/(?<=\w)-\s+(?=\p{Ll})/u', ' ', $out);
        // "T ტიპის" → "T -type": a suffix joins the word before it
        $out = preg_replace('/\s+-(?=\p{L})/u', '-', $out);
        $out = trim(preg_replace('/\s+/u', ' ', $out));

        return mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1);
    }

    /* $changes: [product row with the English row's id and name, the English name] */
    private function write(array $changes, string $english): string
    {
        $backup = 'backups/products_english_'.now()->format('Ymd_His').'.jsonl';
        $previous = DB::table('product_translations')->where('locale', $english)
            ->whereIn('id', array_filter(array_map(fn ($c) => $c[0]->english_id, $changes)))->get()->keyBy('id');

        Storage::disk('local')->put($backup, implode("\n", array_map(
            fn ($c) => json_encode(['product_id' => $c[0]->product_id, 'before' => $previous[$c[0]->english_id] ?? null, 'name' => $c[1]], JSON_UNESCAPED_UNICODE),
            $changes,
        ))."\n");

        foreach (array_chunk($changes, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $english) {
                foreach ($chunk as [$row, $name]) {
                    if ($row->english_id) {
                        // the slug stays: the English address is already known and linked
                        DB::table('product_translations')->where('id', $row->english_id)->update(['name' => mb_substr($name, 0, 255)]);

                        continue;
                    }

                    DB::table('product_translations')->insert([
                        'product_id' => $row->product_id,
                        'locale' => $english,
                        'name' => mb_substr($name, 0, 255),
                        'slug' => Slug::make($name).'-'.$row->product_id,
                    ]);
                }
            });
        }

        return $backup;
    }
}
