<?php

declare(strict_types=1);

namespace App\Services\Digests;

/**
 * The summary chosen for one change in one digest.
 */
final readonly class SummaryResult
{
    public const string AI = 'ai';

    public const string FACTUAL = 'factual';

    public function __construct(
        public string $text,
        public string $source,
        public ?int $changeSummaryId = null,
    ) {}
}
