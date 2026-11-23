<?php

declare(strict_types=1);

namespace App\Ifood\Exceptions;

use RuntimeException;

/**
 * An event that does not set a status (CAR, CARF) arrived for an order we have
 * never seen. The job retries later, when the status events have arrived.
 */
final class OrderNotYetKnownException extends RuntimeException {}
