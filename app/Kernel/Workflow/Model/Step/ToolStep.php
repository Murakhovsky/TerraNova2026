<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use InvalidArgumentException;
use Kernel\Workflow\Model\StepType;

final readonly class ToolStep extends Step
{
    /** @param array<string,mixed> $input */
    public function __construct(
        string $id,
        string $name,
        public string $toolName,
        public array $input = [],
    ) {
        parent::__construct($id, $name);
        if (trim($toolName) === '') {
            throw new InvalidArgumentException('ToolStep requires a tool name.');
        }
    }

    public function type(): StepType { return StepType::TOOL; }
}
