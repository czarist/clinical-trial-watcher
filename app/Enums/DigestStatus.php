<?php

declare(strict_types=1);

namespace App\Enums;

enum DigestStatus: string
{
    /** Created; content not built yet. */
    case Pending = 'pending';

    /** Content built; deliveries may be in progress. */
    case Generated = 'generated';

    /** No matching updates in the window; nothing is sent. */
    case Empty = 'empty';

    /** Paid access ended before generation; nothing is built or sent. */
    case Skipped = 'skipped';
}
