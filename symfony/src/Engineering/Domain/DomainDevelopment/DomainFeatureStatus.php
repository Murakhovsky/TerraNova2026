<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum DomainFeatureStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case READY = 'READY';
    case SCHEDULING = 'SCHEDULING';
    case RUNNING = 'RUNNING';
    case WAITING_HUMAN = 'WAITING_HUMAN';
    case BLOCKED = 'BLOCKED';
    case FAILED = 'FAILED';
    case COMPLETED = 'COMPLETED';
    case STALE = 'STALE';
    case REVALIDATION_REQUIRED = 'REVALIDATION_REQUIRED';
    case CANCELLED = 'CANCELLED';

    public function schedulable(): bool
    {
        return in_array($this, [self::NOT_STARTED, self::READY, self::STALE, self::REVALIDATION_REQUIRED], true);
    }

    public function active(): bool
    {
        return in_array($this, [self::SCHEDULING, self::RUNNING], true);
    }
}
