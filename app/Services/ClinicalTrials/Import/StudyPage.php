<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

final readonly class StudyPage
{
    /**
     * @param  list<array<string, mixed>>  $studies  individual study payloads as returned by the registry
     */
    public function __construct(
        public array $studies,
        public ?string $nextPageToken,
    ) {}

    public function hasNextPage(): bool
    {
        return $this->nextPageToken !== null && $this->nextPageToken !== '';
    }
}
