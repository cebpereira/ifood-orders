<?php

declare(strict_types=1);

namespace App\Ifood\Exceptions;

/**
 * Any other non-2xx response (4xx validation errors, 5xx after retries).
 */
final class IfoodRequestException extends IfoodException {}
