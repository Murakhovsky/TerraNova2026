<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

enum EngineeringWorkflowState: string
{
    case NEW = 'NEW';
    case ANALYSIS = 'ANALYSIS';
    case SPECIFICATION_READY = 'SPECIFICATION_READY';
    case QA_PLANNING = 'QA_PLANNING';
    case ARCHITECTURE_PENDING = 'ARCHITECTURE_PENDING';
    case ARCHITECTURE_APPROVED = 'ARCHITECTURE_APPROVED';
    case DEVELOPMENT_PENDING = 'DEVELOPMENT_PENDING';
    case DEVELOPMENT_RUNNING = 'DEVELOPMENT_RUNNING';
    case REVIEW_PENDING = 'REVIEW_PENDING';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case QA_PENDING = 'QA_PENDING';
    case QA_FAILED = 'QA_FAILED';
    case HUMAN_DECISION_REQUIRED = 'HUMAN_DECISION_REQUIRED';
    case BLOCKED = 'BLOCKED';
    case ESCALATED = 'ESCALATED';
    case READY_FOR_HUMAN_APPROVAL = 'READY_FOR_HUMAN_APPROVAL';
    case DONE = 'DONE';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::DONE, self::CANCELLED, self::FAILED => true,
            default => false,
        };
    }
}
