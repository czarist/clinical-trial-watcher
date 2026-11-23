<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule failure that is reported to the API client with its HTTP status.
 */
abstract class ApplicationException extends RuntimeException
{
    abstract public function status(): int;
}
