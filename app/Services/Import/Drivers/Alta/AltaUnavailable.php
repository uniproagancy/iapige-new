<?php

namespace App\Services\Import\Drivers\Alta;

use RuntimeException;

class AltaUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message, $status ?? 0);
    }
}
