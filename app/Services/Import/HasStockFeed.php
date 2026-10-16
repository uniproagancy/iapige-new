<?php

namespace App\Services\Import;

interface HasStockFeed
{
    public function refreshStock(): int;
}
