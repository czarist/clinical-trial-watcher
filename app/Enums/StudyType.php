<?php

declare(strict_types=1);

namespace App\Enums;

enum StudyType: string
{
    case Interventional = 'INTERVENTIONAL';
    case Observational = 'OBSERVATIONAL';
    case ExpandedAccess = 'EXPANDED_ACCESS';
}
