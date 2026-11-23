<?php

declare(strict_types=1);

namespace App\Services\Registries\Identifiers;

use App\Enums\IdentifierScheme;

/**
 * A normalized registry identifier, such as NCT01234567 or 2019-001234-12 (EudraCT).
 */
final readonly class TrialIdentifier
{
    public function __construct(
        public IdentifierScheme $scheme,
        public string $value,
        public bool $isPrimary = false,
    ) {}

    public function key(): string
    {
        return $this->scheme->value.':'.$this->value;
    }
}
