<?php

namespace App\Services\Import\Drivers\Elite;

use RuntimeException;

/**
 * Raised when Elite's proxy refuses the request rather than answering it.
 *
 * Its own class so the import job can tell an operator problem — an expired
 * worker token, a proxy that is down — apart from a product that simply is not
 * there, and shout about the first one.
 */
class BlockedByElite extends RuntimeException {}
