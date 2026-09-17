<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum WorkflowStatus: string
{
    case CREATED = 'created';
    case RUNNING = 'running';
    case WAITING = 'waiting';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function terminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }
}
