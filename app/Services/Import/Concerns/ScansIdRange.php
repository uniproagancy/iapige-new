<?php

namespace App\Services\Import\Concerns;

use App\Models\ProductOffer;

/**
 * Walking a supplier that will not tell us what it has.
 *
 * Zoommer and Elite both answer "here is product 54321" and neither offers a
 * list, so the only way in is to ask for ids one at a time. That was done by
 * counting from one to the end of the range on every run — thirty-five
 * thousand requests for Elite, more for Zoommer, when the shop holds a few
 * thousand products. At the pace the source tolerates that is most of a day,
 * nearly all of it spent on ids that have never existed.
 *
 * Asked differently now:
 *
 *   - the ids we already hold, because those are the catalogue and their
 *     prices and stock are what the run exists to refresh;
 *   - then a window above the highest id we have ever seen, which is where a
 *     supplier puts anything new.
 *
 * The first run has nothing to go on and still walks the whole range, as does
 * any run asked to. That sweep is what finds a product skipped earlier —
 * one that was out of stock the day we passed it, say — so it is worth doing
 * now and then, just not every night.
 */
trait ScansIdRange
{
    /** How far past the newest known id to look for arrivals. */
    protected const DISCOVER_AHEAD = 1000;

    /**
     * @return iterable<int, string>
     */
    protected function idsToScan(int $defaultTo): iterable
    {
        $from = (int) ($this->supplier->config['from'] ?? 1);
        $to = (int) ($this->supplier->config['to'] ?? $defaultTo);

        $known = ProductOffer::where('supplier_id', $this->supplier->id)
            ->orderBy('external_id')
            ->pluck('external_id');

        // nothing to build on, or somebody asked for everything
        if ($known->isEmpty() || ($this->supplier->config['full_scan'] ?? false)) {
            for ($id = $from; $id <= $to; $id++) {
                yield (string) $id;
            }

            return;
        }

        foreach ($known as $id) {
            yield (string) $id;
        }

        $highest = (int) $known->max(fn ($id) => (int) $id);
        $window = (int) ($this->supplier->config['discover_ahead'] ?? self::DISCOVER_AHEAD);

        $start = max($from, $highest + 1);
        $end = min($to, $highest + $window);

        for ($id = $start; $id <= $end; $id++) {
            yield (string) $id;
        }
    }
}
