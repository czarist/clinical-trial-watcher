<?php

declare(strict_types=1);

namespace App\Enums;

enum TeamRole: string
{
    /** The Team AI subscriber; manages members and shared saved searches. */
    case Owner = 'owner';

    case Member = 'member';
}
