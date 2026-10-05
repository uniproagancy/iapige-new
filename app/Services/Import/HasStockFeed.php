<?php

namespace App\Services\Import;

/**
 * A driver whose supplier publishes stock separately from product details.
 * The stock feed is refreshed on its own schedule — usually far more often
 * than the catalogue itself.
 */
interface HasStockFeed
{
    /** Refresh supplier_stocks from the feed; returns how many rows were written. */
    public function refreshStock(): int;
}
