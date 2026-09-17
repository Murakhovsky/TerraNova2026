<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use Kernel\Workflow\Model\Assignment;
use Kernel\Workflow\Model\StepType;

final readonly class HumanStep extends Step
{
    public function __construct(
        string $id,
        string $name,
        public Assignment $assignment,
        public string $instructions = '',
    ) {
        parent::__construct($id, $name);
    }

    public function type(): StepType { return StepType::HUMAN; }
}
