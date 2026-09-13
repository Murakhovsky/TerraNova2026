<?php
declare(strict_types=1);

namespace Kernel\Llm;

use Kernel\Execution\ClassifiedExecutionFailureInterface;
use Kernel\Execution\ExecutionFailureKind;
use RuntimeException;

final class LlmBudgetExceededException extends RuntimeException implements ClassifiedExecutionFailureInterface
{
    public function __construct(
        public readonly string $organizationId,
        public readonly float $spent,
        public readonly float $limit,
        public readonly string $currency,
    ) {
        parent::__construct(sprintf(
            'LLM monthly budget exceeded for organization %s: %.4f/%.4f %s.',
            $organizationId,
            $spent,
            $limit,
            $currency,
        ));
    }

    public function failureKind(): ExecutionFailureKind
    {
        return ExecutionFailureKind::BudgetExceeded;
    }
}
