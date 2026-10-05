<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum EngineeringDomainFeatureStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case READY = 'READY';
    case RUNNING = 'RUNNING';
    case WAITING = 'WAITING';
    case BLOCKED = 'BLOCKED';
    case FAILED = 'FAILED';
    case COMPLETED = 'COMPLETED';
    case STALE = 'STALE';
    case REVALIDATION_REQUIRED = 'REVALIDATION_REQUIRED';
    case CANCELLED = 'CANCELLED';
}
