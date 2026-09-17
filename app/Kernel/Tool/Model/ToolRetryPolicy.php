<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;

final readonly class ToolRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 1,
        public bool $allowSideEffectRetries = false,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new InvalidArgumentException('Tool retry attempts must be between 1 and 10.');
        }
    }

    public function attemptsFor(ToolDefinition $definition): int
    {
        if ($definition->effect()->hasSideEffects() && !$this->allowSideEffectRetries) {
            return 1;
        }
        return $this->maxAttempts;
    }
}
