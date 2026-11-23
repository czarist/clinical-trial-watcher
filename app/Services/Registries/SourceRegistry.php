<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Models\ClinicalTrials\ClinicalTrial;
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

    /**
     * Registries whose terms do not allow their data in paid features.
     *
     * @return list<string>
     */
    public function withoutCommercialUse(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key): bool => ! $this->get($key)->access()->commercialUse));
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

    /**
     * Public page of a stored record: the registry's own record URL, or else the HTTPS address
     * the importer read the record from (registries without a page per identifier).
     */
    public function urlFor(ClinicalTrial $trial): ?string
    {
        $url = $this->recordUrl((string) $trial->source, $trial->source_id);

        if ($url === null && is_string($trial->raw_url) && str_starts_with($trial->raw_url, 'https://')) {
            return $trial->raw_url;
        }

        return $url;
    }

    /** Text to show where a registry's data appears, as its terms require. */
    public function attribution(string $key): ?string
    {
        return $this->has($key) ? $this->get($key)->access()->attribution : null;
    }

    public function recordUrl(string $key, ?string $sourceId): ?string
    {
        return $sourceId !== null && $this->has($key) ? $this->get($key)->recordUrl($sourceId) : null;
    }
}
