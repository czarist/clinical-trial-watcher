<?php

declare(strict_types=1);

namespace App\Services\Digests;

/**
 * Summaries for the changes of one digest, with the numbers logged for operators.
 */
final readonly class SummaryBatch
{
    /**
     * @param  array<int, SummaryResult>  $results  keyed by change id
     */
    public function __construct(
        public array $results,
        public int $cacheHits,
        public int $generated,
        public int $fallbacks,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $fallbackReason,
    ) {}
}
