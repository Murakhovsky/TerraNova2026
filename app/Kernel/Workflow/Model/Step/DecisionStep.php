<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use Kernel\Workflow\Model\StepType;

final readonly class DecisionStep extends Step
{
    public function type(): StepType { return StepType::DECISION; }
}
