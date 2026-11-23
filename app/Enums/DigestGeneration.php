<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a digest's summaries were written; visible to operators.
 */
enum DigestGeneration: string
{
    /** Every summary came from the model (or its shared cache). */
    case Ai = 'ai';

    /** Some summaries fell back to the factual text. */
    case Partial = 'partial';

    /** Every summary is the factual text built from the recorded changes. */
    case Fallback = 'fallback';
}
