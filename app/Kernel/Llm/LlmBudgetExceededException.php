<?php
declare(strict_types=1);

namespace Kernel\Llm;

use RuntimeException;

final class LlmBudgetExceededException extends RuntimeException
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
}
