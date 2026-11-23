<?php

declare(strict_types=1);

namespace App\Enums;

enum TrialChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';

    public function alertReason(): AlertMatchReason
    {
        return match ($this) {
            self::Created => AlertMatchReason::NewStudy,
            self::Updated => AlertMatchReason::UpdatedStudy,
            self::StatusChanged => AlertMatchReason::StatusChange,
        };
    }
}
