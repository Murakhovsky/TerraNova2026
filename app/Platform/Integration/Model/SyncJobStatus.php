<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

enum SyncJobStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
