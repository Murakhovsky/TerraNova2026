<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum StepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case WAITING = 'waiting';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
