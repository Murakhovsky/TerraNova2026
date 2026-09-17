<?php
declare(strict_types=1);

namespace Kernel\Workflow\Contract;

use Kernel\Workflow\Model\Condition;

interface ConditionEvaluatorInterface
{
    /** @param array<string,mixed> $context */
    public function matches(Condition $condition, array $context): bool;
}
