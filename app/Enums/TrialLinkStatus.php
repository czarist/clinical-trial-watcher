<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of a link between two records that share a registry identifier.
 */
enum TrialLinkStatus: string
{
    /** Found by the import: the records are grouped as one study until someone rejects it. */
    case Auto = 'auto';

    /** Confirmed by a person; kept even if the shared identifier later disappears. */
    case Confirmed = 'confirmed';

    /** Rejected by a person: the records stay separate, whatever later imports find. */
    case Rejected = 'rejected';

    /**
     * Not grouped automatically: the records come from the same registry, or grouping them
     * would put two records of one registry in the same study. Waits for a person.
     */
    case Conflict = 'conflict';

    public function groups(): bool
    {
        return $this === self::Auto || $this === self::Confirmed;
    }

    public function isReviewed(): bool
    {
        return $this === self::Confirmed || $this === self::Rejected;
    }
}
