<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use Kernel\Agent\Model\AgentInstance;
use Kernel\Workflow\Model\StepType;

final readonly class AgentStep extends Step
{
    /** @param array<string,mixed> $input */
    public function __construct(
        string $id,
        string $name,
        public AgentInstance $agent,
        public array $input = [],
    ) {
        parent::__construct($id, $name);
    }

    public function type(): StepType { return StepType::AGENT; }
}
