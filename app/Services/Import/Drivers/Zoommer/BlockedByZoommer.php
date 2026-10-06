<?php

namespace App\Services\Import\Drivers\Zoommer;

use RuntimeException;

/**
 * Raised when Zoommer refuses the request rather than answering it.
 *
 * Its own class so the import job can tell an operator problem — a stale
 * cf_clearance cookie, a user agent that no longer matches it — apart from a
 * product that simply is not there, and shout about the first one.
 */
class BlockedByZoommer extends RuntimeException {}
