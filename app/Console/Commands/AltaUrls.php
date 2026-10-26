<?php

namespace App\Console\Commands;

use App\Services\Import\Drivers\Alta\AltaB2bClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/*
 | Writes out the addresses to ask alta.ge about every item in the B2B price
 | list.
 |
 | The shop front will not answer this server, so the asking is done by hand
 | from somewhere it does answer. This only prepares the list; the answers come
 | back into the shop through the admin's "add from JSON" page.
 */
class AltaUrls extends Command
{
    protected $signature = 'alta:urls
        {--out= : where to write the list; storage/app/alta/suggestions.txt by default}
        {--min-stock=1 : leave out anything with less than this in stock}
        {--limit=0 : only the first so many}
        {--with-names : write the item name beside each address, as a comment}';

    protected $description = 'List the alta.ge lookup addresses for every item in the B2B price list';

    public function handle(): int
    {
        $client = new AltaB2bClient((array) config('services.alta'));

        $this->line('Reading the price list…');

        $rows = $client->all();

        if (! $rows) {
            $this->error('The price list came back empty. Check ALTA_B2B_USER and ALTA_B2B_PASSWORD.');

            return self::FAILURE;
        }

        $this->info(count($rows).' items in the list.');

        $min = (int) $this->option('min-stock');
        $limit = (int) $this->option('limit');

        $wanted = array_values(array_filter(
            $rows,
            fn (array $row) => $row['quantity'] >= $min,
        ));

        $thin = count($rows) - count($wanted);

        if ($limit > 0) {
            $wanted = array_slice($wanted, 0, $limit);
        }

        $lines = [];

        foreach ($wanted as $row) {
            if ($this->option('with-names')) {
                $lines[] = '# '.$row['item'].'  '.$row['name']
                    .'  ('.number_format($row['price'], 2).' GEL, '.$row['quantity'].' in stock)';
            }

            $lines[] = 'https://alta.ge/api/proxy/v1/Products/search/suggestions?query='.rawurlencode($row['item']);
        }

        $path = $this->option('out') ?: storage_path('app/alta/suggestions.txt');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        $this->newLine();
        $this->table(['', 'how many'], [
            ['items in the price list', count($rows)],
            ['  with less than '.$min.' in stock', $thin],
            ['addresses written', count($wanted)],
        ]);

        $this->info('Written to '.$path);

        $this->newLine();
        $this->line('  Each address answers with the shop front\'s own id for that item.');
        $this->line('  That id is what the details address needs:');
        $this->line('    https://alta.ge/api/proxy/v1/Products/details?productId=<id>');
        $this->line('');
        $this->line('  Paste a details answer into the admin, under Import → Add from JSON.');

        return self::SUCCESS;
    }
}
