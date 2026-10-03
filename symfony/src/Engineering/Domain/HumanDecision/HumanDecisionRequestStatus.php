<?php
declare(strict_types=1);

namespace App\Engineering\Domain\HumanDecision;

enum HumanDecisionRequestStatus: string
{
    case OPEN = 'OPEN';
    case ANSWERED = 'ANSWERED';
    case CANCELLED = 'CANCELLED';
}
