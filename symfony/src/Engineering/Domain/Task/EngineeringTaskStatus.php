<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Task;

enum EngineeringTaskStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';
    case CANCELLED = 'CANCELLED';
}
