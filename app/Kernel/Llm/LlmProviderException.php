<?php
declare(strict_types=1);

namespace Kernel\Llm;

use Kernel\Execution\ClassifiedExecutionFailureInterface;
use Kernel\Execution\ExecutionFailureKind;
use RuntimeException;
use Throwable;

final class LlmProviderException extends RuntimeException implements ClassifiedExecutionFailureInterface
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $retryable,
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureKind(): ExecutionFailureKind
    {
        return $this->retryable
            ? ExecutionFailureKind::ExternalUnavailable
            : ExecutionFailureKind::Permanent;
    }
}
