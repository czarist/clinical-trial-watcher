<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The clinical trial registry could not be read after the allowed retries.
 */
final class RegistryRequestFailed extends RuntimeException
{
    public static function fromStatus(int $status, \Throwable $previous): self
    {
        return new self("The registry responded with HTTP {$status}.", $status, $previous);
    }

    public static function fromConnection(\Throwable $previous): self
    {
        return new self('Could not connect to the registry: '.$previous->getMessage(), 0, $previous);
    }

    public static function invalidPayload(): self
    {
        return new self('The registry response does not contain a studies list.');
    }
}
