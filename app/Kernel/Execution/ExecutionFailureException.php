<?php
declare(strict_types=1);

namespace Kernel\Execution;

use RuntimeException;
use Throwable;

class ExecutionFailureException extends RuntimeException implements ClassifiedExecutionFailureInterface
{
    public function __construct(
        private readonly ExecutionFailureKind $kind,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureKind(): ExecutionFailureKind
    {
        return $this->kind;
    }

    public static function retryable(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::Retryable, $message, $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::Permanent, $message, $previous);
    }

    public static function concurrencyConflict(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::ConcurrencyConflict, $message, $previous);
    }

    public static function policyDenied(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::PolicyDenied, $message, $previous);
    }

    public static function budgetExceeded(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::BudgetExceeded, $message, $previous);
    }

    public static function externalUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self(ExecutionFailureKind::ExternalUnavailable, $message, $previous);
    }
}
