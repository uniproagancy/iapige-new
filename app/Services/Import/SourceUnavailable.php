<?php

namespace App\Services\Import;

use RuntimeException;

/*
 | A supplier's site that did not answer this time.
 |
 | Not the same as a product that is not there: the job is tried again later
 | rather than counted as done. Caught like any other failure, a site that
 | timed out once left the row out of the shop until somebody noticed.
 */
class SourceUnavailable extends RuntimeException {}
