<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Finding;

enum FindingSeverity: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';
}
