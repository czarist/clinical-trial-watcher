<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMatchReason: string
{
    case NewStudy = 'new_study';
    case UpdatedStudy = 'updated_study';
    case StatusChange = 'status_change';

    public function label(): string
    {
        return __('mail.alert_matches.reason.'.$this->value);
    }
}
