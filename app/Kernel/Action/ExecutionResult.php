<?php
declare(strict_types=1);

namespace Kernel\Action;

use Kernel\Execution\ExecutionFailureKind;

final readonly class ExecutionResult
{
    private function __construct(
        public bool $successful,
        public array $data,
        public ?string $error,
        public array $metrics = [],
        public ?ExecutionFailureKind $failureKind = null,
        public bool $retryable = false,
    )
    {
    }

    public static function success(array $data = [], array $metrics = []): self
    {
        return new self(true, $data, null, $metrics);
    }

    public static function failure(
        string $error,
        array $data = [],
        array $metrics = [],
        ExecutionFailureKind $failureKind = ExecutionFailureKind::Permanent,
    ): self {
        return new self(false, $data, $error, $metrics, $failureKind, $failureKind->retryable());
    }

    public function status(): string { return $this->successful ? 'SUCCESS' : 'FAILED'; }
}
