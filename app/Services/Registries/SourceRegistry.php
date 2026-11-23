<?php

declare(strict_types=1);

namespace App\Services\Registries;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The registries configured in `config/registries.php`, in priority order. When records of
 * several registries describe the same study, the record of the registry listed first is the
 * one shown.
 */
final class SourceRegistry
{
    /** @var array<string, RegistrySource> */
    private array $resolved = [];

    /**
     * @param  array<string, class-string<RegistrySource>>  $sources  key => class, in priority order
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $sources,
    ) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    public function get(string $key): RegistrySource
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown registry source [{$key}].");
        }

        return $this->resolved[$key] ??= $this->container->make($this->sources[$key]);
    }

    /**
     * Lower is preferred. Unknown sources (no longer configured) come last.
     */
    public function priority(string $key): int
    {
        $position = array_search($key, $this->keys(), true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    public function name(string $key): string
    {
        return $this->has($key) ? $this->get($key)->name() : $key;
    }

    public function recordUrl(string $key, ?string $sourceId): ?string
    {
        return $sourceId !== null && $this->has($key) ? $this->get($key)->recordUrl($sourceId) : null;
    }
}
