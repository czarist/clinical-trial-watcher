<?php

declare(strict_types=1);

namespace App\Enums;

enum StudyPhase: string
{
    case NotApplicable = 'NA';
    case EarlyPhase1 = 'EARLY_PHASE1';
    case Phase1 = 'PHASE1';
    case Phase2 = 'PHASE2';
    case Phase3 = 'PHASE3';
    case Phase4 = 'PHASE4';
}
