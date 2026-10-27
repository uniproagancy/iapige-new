<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaB2bClient;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaJson;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use App\Services\Import\ProductImporter;
use App\Support\Redact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
 | The whole of Alta in one go: the price list says which items exist, the
 | shop front is asked about each one by its code, and what comes back becomes
 | a product.
 |
 | Where alta.ge will not answer this server, the answers can be saved by hand
 | from somewhere it does and handed over with --from. The second half of the
 | run is then exactly the same.
 */
class AltaImport extends Command
{
    protected $signature = 'alta:import
        {--supplier=alta : the supplier code to file these under}
        {--from= : a folder of saved details answers, instead of asking the site}
        {--min-stock=2 : leave out anything with less than this in stock}
        {--limit=0 : only the first so many}
        {--dry : say what would happen and write nothing}';

    protected $description = 'Read Alta end to end: price list, shop front, products';

    public function handle(ProductImporter $importer): int
    {
        $supplier = Supplier::where('code', $this->option('supplier'))->first();

        if (! $supplier) {
            $this->error("No supplier with the code [{$this->option('supplier')}].");

            return self::FAILURE;
        }

        try {
            $answers = $this->option('from')
                ? $this->saved($this->option('from'))
                : $this->asked($supplier);
        } catch (AltaUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($answers === null) {
            return self::FAILURE;
        }

        $reader = new AltaJson($supplier);
        $driver = new AltaDriver($supplier);

        $made = $refreshed = $refused = 0;
        $rows = [];

        $bar = $this->output->createProgressBar(count($answers));
        $bar->start();

        foreach ($answers as $label => $answer) {
            $bar->advance();

            $read = $reader->read(is_string($answer) ? $answer : json_encode($answer));

            if (! $read['payload']) {
                $refused++;
                $rows[] = [$label, '—', '—', implode('; ', $read['errors'])];

                continue;
            }

            try {
                $details = is_string($answer) ? json_decode($answer, true, 512, JSON_THROW_ON_ERROR) : $answer;
                $payload = $driver->fromDetails($read['payload']->externalId, $details);
            } catch (AltaUnavailable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if (! $payload) {
                $refused++;
                $rows[] = [$label, '—', '—', 'no matching complete retail data and B2B quantity'];

                continue;
            }

            if ($this->option('dry')) {
                $rows[] = [
                    $payload->externalId,
                    mb_strimwidth($read['seen']['name'], 0, 36, '…'),
                    number_format($payload->costPrice, 0),
                    count($payload->images).' photos, '.count($payload->specs).' specs',
                ];

                continue;
            }

            $existed = $supplier->offers()->where('external_id', $payload->externalId)->exists();

            try {
                /* Picked out one by one, so an empty shelf is no reason to
                   turn the product away. */
                $product = $importer->import($supplier, $payload, insist: true);
            } catch (\Throwable $e) {
                $refused++;

                Log::channel('import')->warning('alta import failed', [
                    'external' => $payload->externalId,
                    'error' => Redact::secrets($e->getMessage()),
                ]);

                $rows[] = [$payload->externalId, '—', '—', $e->getMessage()];

                continue;
            }

            if (! $product) {
                $refused++;
                $rows[] = [$payload->externalId, '—', '—', 'the importer would not take it'];

                continue;
            }

            $existed ? $refreshed++ : $made++;

            $rows[] = [
                $product->sku,
                mb_strimwidth($product->name, 0, 36, '…'),
                number_format((float) $product->price, 0),
                $product->images()->count().' photos, '.$product->stock.' in stock',
            ];
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['code', 'name', 'price', ''], array_slice($rows, 0, 40));

        if (count($rows) > 40) {
            $this->line('  … and '.(count($rows) - 40).' more');
        }

        $this->newLine();

        if ($this->option('dry')) {
            $this->info(count($answers).' answers read. Nothing was written — drop --dry to apply.');

            return self::SUCCESS;
        }

        $this->line('  added:     '.$made);
        $this->line('  refreshed: '.$refreshed);
        $this->line('  refused:   '.$refused);

        return self::SUCCESS;
    }

    /**
     * Answers saved by hand.
     *
     * @return array<string, string>|null
     */
    protected function saved(string $from): ?array
    {
        if (! file_exists($from)) {
            $this->error("There is nothing at [{$from}].");

            return null;
        }

        $answers = [];

        if (is_dir($from)) {
            foreach (File::files($from) as $file) {
                $answers[$file->getFilenameWithoutExtension()] = $file->getContents();
            }
        } else {
            foreach (preg_split('/\R/', trim(File::get($from))) ?: [] as $i => $line) {
                $line = trim($line);

                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $answers['line '.($i + 1)] = $line;
                }
            }
        }

        $this->info(count($answers).' saved answers.');

        return $answers;
    }

    /**
     * The price list, then the shop front for each item in it.
     *
     * @return array<string, array<string, mixed>>|null
     */
    protected function asked(Supplier $supplier): ?array
    {
        $b2b = new AltaB2bClient((array) config('services.alta'));
        $site = new AltaClient((array) config('services.alta'));

        $this->line('Reading the price list…');

        $list = $b2b->all();

        if (! $list) {
            $this->error('The price list came back empty. Check ALTA_B2B_USER and ALTA_B2B_PASSWORD.');

            return null;
        }

        $min = (int) $this->option('min-stock');
        $limit = (int) $this->option('limit');

        $wanted = array_values(array_filter($list, fn (array $row) => $row['quantity'] >= $min));

        if ($limit > 0) {
            $wanted = array_slice($wanted, 0, $limit);
        }

        $this->info(count($list).' items, '.count($wanted).' of them worth asking about.');

        $bar = $this->output->createProgressBar(count($wanted));
        $bar->start();

        $answers = [];
        $unlisted = $silent = 0;

        foreach ($wanted as $row) {
            $bar->advance();

            $details = $site->product($row['item']);

            if (! $details) {
                $unlisted++;

                continue;
            }

            $answers[$row['item']] = $details;
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('  answers:            '.count($answers));
        $this->line('  not on the site:    '.$unlisted);
        $this->line('  asked but no reply: '.$silent);

        if (($unlisted + $silent) && ! $answers) {
            $this->newLine();
            $this->warn('The shop front answered nothing at all. If it refuses this server,');
            $this->warn('save the answers elsewhere and hand them over with --from.');
        }

        return $answers;
    }
}
