<?php

namespace App\Services\Import\Drivers\Alta;

use RuntimeException;

/**
 * Raised when Alta's proxy refuses the request rather than answering it.
 *
 * Its own class so the import job can tell an operator problem — a worker token,
 * a Cloudflare challenge nobody has a clearance cookie for — apart from a
 * product that simply is not listed, and shout about the first one.
 */
class BlockedByAlta extends RuntimeException {}
