<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * The provider could not be reached or answered without usable content. The message never
 * contains the API key or the request and response bodies.
 */
final class AiProviderFailed extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('AI provider is disabled or not configured.');
    }

    public static function httpStatus(int $status): self
    {
        return new self("AI provider answered with HTTP {$status}.");
    }

    public static function connection(string $reason): self
    {
        return new self("AI provider could not be reached: {$reason}");
    }

    public static function emptyContent(): self
    {
        return new self('AI provider returned no message content.');
    }
}
