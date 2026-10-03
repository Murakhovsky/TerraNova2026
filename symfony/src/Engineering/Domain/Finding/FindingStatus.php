<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Finding;

enum FindingStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
    case ACCEPTED = 'ACCEPTED';
    case DISMISSED = 'DISMISSED';
}
