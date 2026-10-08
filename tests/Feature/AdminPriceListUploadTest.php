<?php

namespace Tests\Feature;

use App\Jobs\ImportProductJob;
use App\Livewire\Admin\Import\Upload;
use App\Models\Supplier;
use App\Models\SupplierStock;
use App\Models\User;
use App\Services\Import\ProductPayload;
use App\Services\Import\SupplierDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A price list, uploaded instead of left on the server.
 *
 * import:file reads a path, so a spreadsheet that arrives by email meant an
 * FTP client and then a terminal. The reading is shared with the command —
 * these cover the part the browser adds, and the rules that stop the wrong
 * file being loaded quietly.
 */
class AdminPriceListUploadTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    public function test_a_csv_price_list_is_loaded(): void
    {
        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('file', $this->csv([
                ['code', 'price', 'stock'],
                ['A-1', '150', '10'],
                ['A-2', '220', '3'],
            ]))
            ->call('load')
            ->assertHasNoErrors();

        $this->assertSame(2, SupplierStock::count());
        $this->assertSame(150.0, (float) SupplierStock::where('external_id', 'A-1')->value('cost_price'));
        $this->assertSame(10, SupplierStock::where('external_id', 'A-1')->value('quantity'));
    }

    /** The file is the whole truth: what it stops listing is out of stock. */
    public function test_rows_missing_from_the_new_file_go_to_zero(): void
    {
        $component = Livewire::test(Upload::class)->set('code', 'ingco');

        $component->set('file', $this->csv([
            ['code', 'price', 'stock'],
            ['A-1', '150', '10'],
            ['A-2', '220', '5'],
        ]))->call('load');

        $component->set('file', $this->csv([
            ['code', 'price', 'stock'],
            ['A-1', '160', '8'],
        ]))->call('load');

        $this->assertSame(8, SupplierStock::where('external_id', 'A-1')->value('quantity'));
        $this->assertSame(0, SupplierStock::where('external_id', 'A-2')->value('quantity'));
    }

    /** The header row is skipped, and how many is the admin's to say. */
    public function test_the_header_row_is_not_imported(): void
    {
        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('file', $this->csv([
                ['code', 'price', 'stock'],
                ['A-1', '150', '10'],
            ]))
            ->call('load');

        $this->assertNull(SupplierStock::where('external_id', 'code')->first());
    }

    /* ------------------------------------------------------------------ refusals */

    /** Anything that is not a spreadsheet is refused before it is read. */
    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('file', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->call('load')
            ->assertHasErrors('file');

        $this->assertSame(0, SupplierStock::count());
    }

    /**
     * A supplier with no column map cannot read a file at all.
     *
     * It is left out of the picker, so the screen cannot offer a supplier it
     * would then fail on.
     */
    public function test_only_suppliers_with_a_column_map_are_offered(): void
    {
        Supplier::create([
            'code' => 'zoommer', 'name' => 'Zoommer', 'driver' => 'X', 'is_active' => true,
            'priority' => 2, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['from' => 1, 'to' => 100],
        ]);

        Livewire::test(Upload::class)
            ->assertSee('Ingco')
            ->assertDontSee('Zoommer');
    }

    /** A file nobody can map produces a message, not a silent nothing. */
    public function test_a_file_with_no_matching_rows_says_so(): void
    {
        $component = Livewire::test(Upload::class)
            ->set('code', 'ingco')
            ->set('file', $this->csv([['code', 'price', 'stock']]))
            ->call('load');

        $this->assertSame(0, $component->get('result')['read']);
    }

    /* ------------------------------------------------------------------ the next step */

    /** Loading prices is half of it; the products are queued afterwards. */
    public function test_the_products_can_be_queued_from_the_same_screen(): void
    {
        Queue::fake();

        $this->supplier->update(['driver' => TwoIdDriver::class]);

        Livewire::test(Upload::class)->set('code', 'ingco')->call('queueImport');

        Queue::assertPushed(ImportProductJob::class, 2);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'code' => 'ingco', 'name' => 'Ingco', 'driver' => 'X', 'is_active' => true,
            'priority' => 1, 'markup' => [['up_to' => 5000, 'percent' => 20]],
            'config' => ['columns' => ['key' => 'A', 'cost' => 'B', 'stock' => 'C']],
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    /** @param  array<int, array<int, string>>  $rows */
    protected function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn (array $row) => implode(',', $row), $rows);

        return UploadedFile::fake()->createWithContent('prices.csv', implode("\n", $lines));
    }
}

/** Two ids, so queueing has something to push without touching the network. */
class TwoIdDriver implements SupplierDriver
{
    public function __construct(public Supplier $supplier) {}

    public function ids(): iterable
    {
        return ['A-1', 'A-2'];
    }

    public function fetch(string $externalId): ?ProductPayload
    {
        return null;
    }
}
