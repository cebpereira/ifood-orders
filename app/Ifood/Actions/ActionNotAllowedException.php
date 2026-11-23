<?php

declare(strict_types=1);

namespace App\Ifood\Actions;

use RuntimeException;

/** The message is shown to the operator as is. */
final class ActionNotAllowedException extends RuntimeException {}
