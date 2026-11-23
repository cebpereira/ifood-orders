<?php

declare(strict_types=1);

namespace App\Ifood\Exceptions;

/**
 * The order (or other resource) does not exist, or does not exist *yet*: order details may 404 right after PLC.
 */
final class IfoodNotFoundException extends IfoodException {}
