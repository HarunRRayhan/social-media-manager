<?php

namespace App\Publishers;

use RuntimeException;

/**
 * The platform refused the account's token or a permission it lacks, so the account has to be connected again
 * (for example, to grant a delete scope that was added after it was first connected).
 */
class ReconnectRequired extends RuntimeException {}
