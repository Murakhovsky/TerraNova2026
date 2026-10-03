<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

enum AgentRunStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case RECOVERY_REQUIRED = 'RECOVERY_REQUIRED';
}
