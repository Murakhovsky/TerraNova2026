<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum DomainDevelopmentStatus: string
{
    case DRAFT = 'DRAFT';
    case ANALYSIS = 'ANALYSIS';
    case DECOMPOSITION = 'DECOMPOSITION';
    case ARCHITECTURE = 'ARCHITECTURE';
    case READY_FOR_IMPLEMENTATION = 'READY_FOR_IMPLEMENTATION';
    case IMPLEMENTATION = 'IMPLEMENTATION';
    case INTEGRATION = 'INTEGRATION';
    case DOMAIN_QA = 'DOMAIN_QA';
    case HUMAN_APPROVAL = 'HUMAN_APPROVAL';
    case RELEASE_READY = 'RELEASE_READY';
    case COMPLETED = 'COMPLETED';
    case BLOCKED = 'BLOCKED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
