<?php

namespace App\Console\Commands;

use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/*
 | The second half of the walk: a lookup answer names the shop front's own id
 | for an item, and that id is what the details address is built from.
 |
 | The answers can come either way. If alta.ge answers this server, they are
 | fetched here; if it does not, they are saved by hand from somewhere it does
 | and read out of a file or a folder instead.
 */
class AltaDetails extends Command
{
    protected $signature = 'alta:details
        {--from= : a file of saved lookup answers, or a folder of them named <item>.json}
        {--out= : where to write the list; storage/app/alta/details.txt by default}
        {--min-stock=1 : when fetching, leave out anything with less than this in stock}
        {--limit=0 : only the first so many}
        {--with-names : write the item code beside each address, as a comment}';

    protected $description = 'Turn the alta.ge lookup answers into details addresses';

    public function handle(): int
    {
        $found = $this->option('from')
            ? $this->fromSaved($this->option('from'))
            : $this->fromSite();

        if ($found === null) {
            return self::FAILURE;
        }

        if (! $found) {
            $this->warn('No ids were found, so there is nothing to write.');

            return self::SUCCESS;
        }

        $lines = [];

        foreach ($found as $id => $about) {
            if ($this->option('with-names')) {
                $lines[] = '# '.$about;
            }

            $lines[] = AltaClient::detailsUrl($id);
        }

        $path = $this->option('out') ?: storage_path('app/alta/details.txt');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        $this->newLine();
        $this->info(count($found).' addresses written to '.$path);

        $this->newLine();
        $this->line('  Ask each one the way you asked the lookups, then paste what comes');
        $this->line('  back into the admin, under Import → Add from JSON.');

        return self::SUCCESS;
    }

    /**
     * Ids read out of answers that were saved by hand.
     *
     * @return array<int, string>|null
     */
    protected function fromSaved(string $from): ?array
    {
        if (! file_exists($from)) {
            $this->error("There is nothing at [{$from}].");

            return null;
        }

        $found = [];

        foreach ($this->answers($from) as $label => $text) {
            $data = json_decode($text, true);

            if (! is_array($data)) {
                $this->warn('  not json: '.$label);

                continue;
            }

            $id = $this->idIn($data);

            if (! $id) {
                $this->line('  no product in it: '.$label);

                continue;
            }

            $found[$id] = $label;
        }

        $this->newLine();
        $this->line('  answers read: '.count(iterator_to_array($this->answers($from))));
        $this->line('  ids found:    '.count($found));

        return $found;
    }

    /**
     * Each saved answer, by the name it is known under.
     *
     * @return \Generator<string, string>
     */
    protected function answers(string $from): \Generator
    {
        if (is_dir($from)) {
            foreach (File::files($from) as $file) {
                yield $file->getFilenameWithoutExtension() => $file->getContents();
            }

            return;
        }

        /* One answer per line is how a shell loop writes them. A file holding
           a single answer works just as well. */
        $lines = preg_split('/\R/', trim(File::get($from))) ?: [];

        foreach ($lines as $i => $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                yield 'line '.($i + 1) => $line;
            }
        }
    }

    /**
     * Ids fetched from the shop front, for everything in the price list.
     *
     * @return array<int, string>|null
     */
    protected function fromSite(): ?array
    {
        $b2b = new AltaB2bClient((array) config('services.alta'));
        $site = new AltaClient((array) config('services.alta'));

        $this->line('Reading the price list…');

        $rows = $b2b->all();

        if (! $rows) {
            $this->error('The price list came back empty. Check ALTA_B2B_USER and ALTA_B2B_PASSWORD.');

            return null;
        }

        $min = (int) $this->option('min-stock');
        $limit = (int) $this->option('limit');

        $wanted = array_values(array_filter($rows, fn (array $row) => $row['quantity'] >= $min));

        if ($limit > 0) {
            $wanted = array_slice($wanted, 0, $limit);
        }

        $this->info(count($wanted).' to ask about.');

        $bar = $this->output->createProgressBar(count($wanted));
        $bar->start();

        $found = [];
        $missing = 0;

        foreach ($wanted as $row) {
            $onSite = $site->suggest($row['item']);

            if ($onSite && $onSite['id']) {
                $found[$onSite['id']] = $row['item'].'  '.$row['name'];
            } else {
                $missing++;
            }

            $bar->advance();
        }

        $bar->finish();

        $this->newLine(2);
        $this->line('  ids found:  '.count($found));
        $this->line('  not found:  '.$missing);

        if ($missing) {
            $this->line('');
            $this->line('  A code can be missing for two reasons: Alta does not list it online,');
            $this->line('  or the shop front refused this server. The import log says which.');
        }

        return $found;
    }

    /**
     * The shop front's id inside one lookup answer.
     */
    protected function idIn(array $data): ?int
    {
        /* Either the lookup's own shape, or a details answer being reused. */
        $id = $data['products'][0]['id']
            ?? $data['product']['id']
            ?? $data['id']
            ?? null;

        return (int) $id ?: null;
    }
}
