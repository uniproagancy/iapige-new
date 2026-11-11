<?php

namespace App\Services\Import\Drivers\Alta;

use App\Services\Import\SourceUnavailable;

class AltaUnavailable extends SourceUnavailable
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message, $status ?? 0);
    }
}
