<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

enum AgentRunStatus: string
{
    case CREATED = 'created';
    case QUEUED = 'queued';
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

    public function allows(self $next): bool
    {
        return match ($this) {
            self::CREATED => in_array($next, [self::QUEUED, self::CANCELLED], true),
            self::QUEUED => in_array($next, [self::RUNNING, self::FAILED, self::CANCELLED], true),
            self::RUNNING => in_array($next, [self::WAITING, self::COMPLETED, self::FAILED, self::CANCELLED], true),
            self::WAITING => in_array($next, [self::RUNNING, self::FAILED, self::CANCELLED], true),
            self::COMPLETED, self::FAILED, self::CANCELLED => false,
        };
    }
}
