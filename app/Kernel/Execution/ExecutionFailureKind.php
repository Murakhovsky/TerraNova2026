<?php
declare(strict_types=1);

namespace Kernel\Execution;

enum ExecutionFailureKind: string
{
    case Retryable = 'RETRYABLE';
    case Permanent = 'PERMANENT';
    case ConcurrencyConflict = 'CONCURRENCY_CONFLICT';
    case PolicyDenied = 'POLICY_DENIED';
    case BudgetExceeded = 'BUDGET_EXCEEDED';
    case ExternalUnavailable = 'EXTERNAL_UNAVAILABLE';

    public function retryable(): bool
    {
        return match ($this) {
            self::Retryable,
            self::ConcurrencyConflict,
            self::ExternalUnavailable => true,
            self::Permanent,
            self::PolicyDenied,
            self::BudgetExceeded => false,
        };
    }
}
