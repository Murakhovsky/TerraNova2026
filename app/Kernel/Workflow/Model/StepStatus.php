<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum StepStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case AMBIGUOUS = 'ambiguous';
    case CANCELLED = 'cancelled';
    case RUNNING = 'running';
    case WAITING = 'waiting';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
