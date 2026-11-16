<?php

namespace App\Services\Import;

/*
 | A supplier whose stock comes from an uploaded file, matched to its offers by
 | something other than the offer's external id. import:sync-stock asks the
 | driver instead of matching ids itself, which for such a supplier would
 | match nothing and empty every offer.
 */
interface SyncsStockFile
{
    /* Sets each offer's stock from the loaded file; returns how many changed. */
    public function syncStockFile(): int;
}
