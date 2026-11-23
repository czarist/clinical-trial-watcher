<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Overall recruitment status values used by ClinicalTrials.gov API v2.
 */
enum StudyStatus: string
{
    case ActiveNotRecruiting = 'ACTIVE_NOT_RECRUITING';
    case Completed = 'COMPLETED';
    case EnrollingByInvitation = 'ENROLLING_BY_INVITATION';
    case NotYetRecruiting = 'NOT_YET_RECRUITING';
    case Recruiting = 'RECRUITING';
    case Suspended = 'SUSPENDED';
    case Terminated = 'TERMINATED';
    case Withdrawn = 'WITHDRAWN';
    case Available = 'AVAILABLE';
    case NoLongerAvailable = 'NO_LONGER_AVAILABLE';
    case TemporarilyNotAvailable = 'TEMPORARILY_NOT_AVAILABLE';
    case ApprovedForMarketing = 'APPROVED_FOR_MARKETING';
    case Withheld = 'WITHHELD';
    case Unknown = 'UNKNOWN';
}
